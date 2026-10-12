<?php

namespace App\Services;

use App\Models\{Invitation, SurveyResponse, FollowUp};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResponseService
{
    public function save(Invitation $invitation, array $input, bool $draft = false): ?SurveyResponse
    {
        return DB::transaction(function () use ($invitation, $input, $draft) {
            $inv = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if ($inv->completed_at) {
                throw ValidationException::withMessages(['survey' => __('Respons Anda sudah tercatat.')]);
            }
            $survey = $inv->survey()->firstOrFail();
            if (!$survey->isOpen()) {
                throw ValidationException::withMessages(['survey' => __('Survei sedang tidak menerima respons.')]);
            }
            $questions = $survey->questions;
            $rules = [];
            foreach ($questions as $q) {
                $prefix = 'answers.'.$q->id;
                $allowed = ($q->allow_other ?? false) ? array_merge($q->options ?? [], ['__other__']) : ($q->options ?? []);
                if ($q->type === 'multiple') {
                    $rules[$prefix.'.value'] = [(!$draft && $q->required) ? 'required' : 'nullable', 'array'];
                    $rules[$prefix.'.value.*'] = ['string', 'distinct', Rule::in($allowed)];
                } else {
                    $rules[$prefix.'.value'] = [(!$draft && $q->required) ? 'required' : 'nullable', 'string', 'max:5000'];
                    if ($q->type === 'rating') $rules[$prefix.'.value'][] = Rule::in(['1','2','3','4','5']);
                    if ($q->type === 'choice') $rules[$prefix.'.value'][] = Rule::in($allowed);
                }
                $chosen = (array) data_get($input, $prefix.'.value', []);
                $rules[$prefix.'.other'] = [(!$draft && in_array('__other__', $chosen, true)) ? 'required' : 'nullable', 'string', 'max:255'];

                // BARU: komentar wajib untuk rating 1-3 (sebelumnya hilang dari versi ini)
                $low = $q->type === 'rating' && in_array((string) data_get($input, $prefix.'.value'), ['1','2','3'], true);
                $rules[$prefix.'.comment'] = [(!$draft && $low) ? 'required' : 'nullable', 'string', 'max:5000'];
            }
            Validator::make($input, $rules, ['required' => __('Pertanyaan wajib atau komentar untuk skor 1–3 belum diisi.'), 'in' => __('Pilihan jawaban tidak valid.')])->validate();

            $answers = [];
            $scores = [];
            $hasLow = false;
            foreach ($questions as $q) {
                // loop pengumpulan jawaban
                $raw = data_get($input, 'answers.'.$q->id.'.value', '');
                $value = is_array($raw)
                    ? array_values(array_filter(array_map(fn($v) => trim((string)$v), $raw), fn($v) => $v !== ''))
                    : trim((string)$raw);
                $comment = trim((string)data_get($input, 'answers.'.$q->id.'.comment', ''));
                $other = trim((string)data_get($input, 'answers.'.$q->id.'.other', ''));
                if (!in_array('__other__', (array)$value, true)) $other = '';
                $answers[$q->id] = ['value' => $value, 'comment' => $comment, 'other' => $other];

                if ($q->type === 'rating' && $value !== '') {
                    $scores[] = (int)$value;
                    $hasLow = $hasLow || (int)$value <= 3;
                }
            }
            $inv->started_at ??= now();
            if ($draft) {
                $inv->draft_answers = $answers;
                $inv->save();
                return null;
            }
            $response = SurveyResponse::create(['invitation_id' => $inv->id, 'survey_id' => $survey->id, 'client_id' => $inv->client_id, 'score' => count($scores) ? round(array_sum($scores) / count($scores), 2) : null, 'submitted_at' => now()]);
            foreach ($questions as $q) {
                $response->answers()->create(['question_id' => $q->id, 'question_text' => $q->text, 'category' => $q->category, 'type' => $q->type, 'value' => $this->display($answers[$q->id]), 'comment' => $answers[$q->id]['comment']]);
            }
            $inv->forceFill(['completed_at' => now(), 'draft_answers' => null])->save();
            if ($hasLow) {
                FollowUp::create(['survey_response_id' => $response->id, 'status' => 'open', 'due_at' => today()->addDays(3)]);
            }
            app(SurveyCompletionNotifier::class)->record($response);
            return $response;
        }, 3);
    }

    private function display(array $a): string
    {
        $vals = array_filter((array)$a['value'], fn($v) => $v !== '');
        $vals = array_map(fn($v) => $v === '__other__' ? 'Lainnya: '.$a['other'] : $v, $vals);
        return implode('; ', $vals);
    }
}