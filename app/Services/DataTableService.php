<?php

namespace App\Services;

use App\Models\{Client, Survey, User, SurveyResponse};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Shared read-only table requests. Existing routes retain their auth middleware. */
class DataTableService
{
    public function respond(Request $request, Builder $query, string $table): JsonResponse
    {
        $request->validate([
            'draw' => 'required|integer|min:0',
            'start' => 'required|integer|min:0|max:10000000',
            'length' => 'required|integer|in:5,10,25,50,100',
            'search' => 'sometimes|array',
            'search.value' => 'nullable|string|max:255',
            'order' => 'sometimes|array|max:1',
            'order.0.column' => 'required_with:order|integer|min:0|max:20',
            'order.0.dir' => 'required_with:order|in:asc,desc',
        ]);
        $total = (clone $query)->count();
        $term = trim((string) $request->input('search.value', ''));
        if ($term !== '') {
            $this->search($query, $table, $term);
        }
        $filtered = (clone $query)->count();

        if ($table === 'responses' && $request->filled('category')) {
            $query->withAvg(['answers as filtered_score' => fn ($q) => $q
                ->where('category', $request->input('category'))->where('type', 'rating')
                ->whereNotNull('value')->where('value', '!=', '')], 'value');
        }
        // Client-provided column names never reach SQL; only the allow-list below is used.
        $columns = $this->columns($table, $request);
        $column = $columns[$request->integer('order.0.column', -1)] ?? null;
        if ($column !== null) {
            $query->reorder()->orderBy($column, $request->input('order.0.dir', 'asc'));
        }
        $query->orderBy($query->getModel()->qualifyColumn('id'), 'desc');
        $rows = $query->offset($request->integer('start'))->limit($request->integer('length'))->get();
        if ($table === 'responses' && $request->filled('category')) {
            foreach ($rows as $row) $row->score = $row->filtered_score === null ? null : round((float) $row->filtered_score, 2);
        }
        $variable = ['clients' => 'clients', 'surveys' => 'surveys', 'invitations' => 'invitations',
            'responses' => 'rows', 'followups' => 'followups', 'bank' => 'questions'][$table];
        $html = view($table.'.rows', [
            $variable => $rows,
            'tableOffset' => $request->integer('start'),
            'showAction' => true,
            'emailEnabled' => config('survey.email_enabled') && config('mail.default') === 'smtp',
        ])->render();

        return response()->json([
            'draw' => $request->integer('draw'), 'recordsTotal' => $total,
            'recordsFiltered' => $filtered, 'html' => $html,
        ]);
    }

    private function search(Builder $query, string $table, string $term): void
    {
        $fields = [
            'clients' => ['name', 'contact', 'email', 'project'],
            'surveys' => ['title', 'description'],
            'invitations' => ['recipient_name', 'recipient_email'],
            'responses' => [], 'followups' => ['notes'],
            'bank' => ['text', 'category'],
        ][$table];
        $relations = [
            'invitations' => ['client' => ['name', 'project'], 'survey' => ['title']],
            'responses' => ['client' => ['name', 'project'], 'survey' => ['title'], 'answers' => ['comment', 'question_text', 'category']],
            'followups' => ['response.client' => ['name', 'project'], 'response.survey' => ['title'], 'assignee' => ['name']],
        ][$table] ?? [];
        $query->where(function (Builder $q) use ($fields, $relations, $term, $table) {
            foreach ($fields as $field) $q->orWhere($field, 'like', '%'.$term.'%');
            foreach ($relations as $relation => $columns) {
                $q->orWhereHas($relation, function (Builder $related) use ($columns, $term) {
                    $related->where(function (Builder $nested) use ($columns, $term) {
                        foreach ($columns as $field) $nested->orWhere($field, 'like', '%'.$term.'%');
                    });
                });
            }
            $statuses = [
                'surveys' => ['aktif' => 'active', 'draf' => 'draft', 'ditutup' => 'closed'],
                'followups' => ['terbuka' => 'open', 'diproses' => 'in_progress', 'selesai' => 'resolved'],
                'bank' => ['rating' => 'rating', 'pilihan ganda' => 'choice', 'teks terbuka' => 'text'],
            ][$table] ?? [];
            $term = mb_strtolower($term);
            if (isset($statuses[$term])) $q->orWhere($table === 'bank' ? 'type' : 'status', $statuses[$term]);
            if ($table === 'clients' && in_array($term, ['aktif', 'nonaktif'], true)) $q->orWhere('active', $term === 'aktif');
            if ($table === 'bank' && in_array($term, ['wajib', 'opsional'], true)) $q->orWhere('required', $term === 'wajib');
            if ($table === 'invitations') {
                if ($term === 'selesai') $q->orWhereNotNull('completed_at');
                if ($term === 'belum mengisi') $q->orWhere(fn ($x) => $x->whereNull('started_at')->whereNull('completed_at'));
                if ($term === 'draf tersimpan') $q->orWhere(fn ($x) => $x->whereNotNull('started_at')->whereNull('completed_at'));
            }
        });
    }

    private function columns(string $table, Request $request): array
    {
        return match ($table) {
            'clients' => [0 => 'name', 1 => 'contact', 2 => 'project', 3 => 'active', 4 => 'invitations_count'],
            'surveys' => [1 => 'title', 2 => 'starts_at', 3 => 'status', 4 => 'invitations_count', 5 => 'responses_count'],
            'bank' => [0 => 'text', 1 => 'category', 2 => 'type', 3 => 'required'],
            'invitations' => [
                0 => Client::select('name')->whereColumn('clients.id', 'invitations.client_id'),
                1 => 'recipient_name', 2 => 'completed_at', 4 => 'reminder_count',
            ],
            'responses' => [
                0 => Client::select('name')->whereColumn('clients.id', 'survey_responses.client_id'),
                1 => Survey::select('title')->whereColumn('surveys.id', 'survey_responses.survey_id'),
                2 => $request->filled('category') ? 'filtered_score' : 'score', 3 => 'submitted_at',
            ],
            'followups' => [
                1 => Client::select('name')->where('clients.id', SurveyResponse::select('client_id')
                    ->whereColumn('survey_responses.id', 'follow_ups.survey_response_id')),
                2 => User::select('name')->whereColumn('users.id', 'follow_ups.assigned_to'),
                3 => 'due_at', 4 => 'status',
            ],
        };
    }
}
