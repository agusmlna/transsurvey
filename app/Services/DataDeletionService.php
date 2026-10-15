<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DataDeletionService
{
    public const TYPES = ['invitations', 'responses', 'clients', 'surveys'];

    /** Raw rows are hashed in memory, never stored in the session or audit. */
    public function inspect(string $type, array $ids, bool $lock = false): array
    {
        abort_unless(in_array($type, self::TYPES, true), 422);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if (!$ids || count($ids) > 100) $this->invalid('selection');
        $table = $type === 'responses' ? 'survey_responses' : $type;
        $selected = $this->rows($table, 'id', $ids, $type === 'responses' ? false : $lock);
        if (count($selected) !== count($ids)) $this->invalid('stale');
        $data = [];
        $counts = array_fill_keys(['invitations', 'responses', 'answers', 'followups', 'deliveries', 'notifications', 'clients', 'surveys', 'questions', 'imports'], 0);
        $labels = [];
        if (in_array($type, ['invitations', 'responses'], true)) {
            $invIds = $type === 'invitations' ? $ids : array_column($selected, 'invitation_id');
            // Match the submission and invitation email lock order: invitation first.
            $invitations = $this->rows('invitations', 'id', $invIds, $lock);
            if (count($invitations) !== count($invIds)) $this->invalid('stale');
            $responses = $this->rows('survey_responses', 'invitation_id', $invIds, $lock);
            if ($type === 'responses' && count(array_intersect($ids, array_column($responses, 'id'))) !== count($ids)) $this->invalid('stale');
            $responseIds = array_column($responses, 'id');
            $data = [
                'invitations' => $invitations,
                'responses' => $responses,
                'answers' => $this->rows('answers', 'survey_response_id', $responseIds, $lock),
                'followups' => $this->rows('follow_ups', 'survey_response_id', $responseIds, $lock),
                'deliveries' => $this->rows('email_deliveries', 'invitation_id', $invIds, $lock),
                'notifications' => $this->rows('admin_survey_notifications', 'survey_response_id', $responseIds, $lock),
            ];
            // A completion worker claims 'sending' before touching SMTP. Refuse cleanup
            // until delivery finishes; do not hold a database transaction across SMTP.
            foreach (array_merge($data['notifications'], $data['deliveries']) as $row) {
                if ($row['status'] === 'sending') $this->invalid('sending');
            }
            foreach ($invitations as $inv) {
                $client = DB::table('clients')->find($inv['client_id']);
                $survey = DB::table('surveys')->find($inv['survey_id']);
                $labels[] = ['id' => $inv['id'], 'name' => $client?->name ?? '—', 'detail' => $survey?->title ?? '—'];
            }
        } elseif ($type === 'clients') {
            if (DB::table('invitations')->whereIn('client_id', $ids)->exists()
                || DB::table('survey_responses')->whereIn('client_id', $ids)->exists()) $this->invalid('client_used');
            $data['clients'] = $selected;
            foreach ($selected as $row) $labels[] = ['id' => $row['id'], 'name' => $row['name'], 'detail' => $row['project']];
        } else {
            foreach ($selected as $row) if ($row['status'] !== 'draft') $this->invalid('survey_used');
            if (DB::table('invitations')->whereIn('survey_id', $ids)->exists()
                || DB::table('survey_responses')->whereIn('survey_id', $ids)->exists()) $this->invalid('survey_used');
            $data['surveys'] = $selected;
            $data['questions'] = $this->rows('questions', 'survey_id', $ids, $lock);
            // Remove import previews too: an old preview must not recreate a deleted draft.
            $data['imports'] = $this->rows('question_imports', 'survey_id', $ids, $lock);
            foreach ($selected as $row) $labels[] = ['id' => $row['id'], 'name' => $row['title'], 'detail' => $row['status']];
        }
        foreach ($data as $key => $rows) $counts[$key] = count($rows);
        return [
            'ids' => $ids, 'counts' => $counts, 'labels' => $labels,
            'invitation_ids' => array_column($data['invitations'] ?? [], 'id'),
            'response_ids' => array_column($data['responses'] ?? [], 'id'),
            'fingerprint' => hash('sha256', json_encode([$type, $data, $labels], JSON_THROW_ON_ERROR)),
        ];
    }

    public function delete(array $preview, User $actor): void
    {
        abort_unless($actor->role === 'admin' && $preview['user_id'] === $actor->id, 403);
        DB::transaction(function () use ($preview, $actor) {
            if (DB::table('data_deletion_audits')->where('request_token', $preview['token'])->where('user_id', $actor->id)->exists()) return;
            if ($preview['expires_at'] < now()->timestamp) $this->invalid('expired');
            $state = $this->inspect($preview['type'], $preview['ids'], true);
            if (!hash_equals($preview['fingerprint'], $state['fingerprint'])) $this->invalid('stale');
            $ids = $state['ids'];
            if (in_array($preview['type'], ['invitations', 'responses'], true)) {
                $responseIds = $state['response_ids'];
                DB::table('admin_survey_notifications')->whereIn('survey_response_id', $responseIds)->delete();
                DB::table('follow_ups')->whereIn('survey_response_id', $responseIds)->delete();
                DB::table('answers')->whereIn('survey_response_id', $responseIds)->delete();
                DB::table('survey_responses')->whereIn('id', $responseIds)->delete();
                DB::table('email_deliveries')->whereIn('invitation_id', $state['invitation_ids'])->delete();
                DB::table('invitations')->whereIn('id', $state['invitation_ids'])->delete();
            } elseif ($preview['type'] === 'surveys') {
                DB::table('question_imports')->whereIn('survey_id', $ids)->delete();
                DB::table('questions')->whereIn('survey_id', $ids)->delete();
                DB::table('surveys')->whereIn('id', $ids)->delete();
            } else {
                DB::table('clients')->whereIn('id', $ids)->delete();
            }
            // No tokens, access codes, response contents, or SMTP credentials in this log.
            DB::table('data_deletion_audits')->insert([
                'request_token' => $preview['token'], 'user_id' => $actor->id,
                'actor_name' => $actor->name, 'entity' => $preview['type'],
                'record_ids' => json_encode($ids), 'summary' => json_encode($state['counts']),
                'reason' => $preview['reason'], 'created_at' => now(),
            ]);
        }, 3);
    }

    private function rows(string $table, string $column, array $ids, bool $lock): array
    {
        $query = DB::table($table)->whereIn($column, $ids)->orderBy('id');
        if ($lock) $query->lockForUpdate();
        return $query->get()->map(fn ($row) => (array) $row)->all();
    }
    private function invalid(string $key): never
    {
        throw ValidationException::withMessages(['cleanup' => __('data_cleanup.'.$key)]);
    }
}
