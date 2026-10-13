<?php

namespace App\Http\Controllers;

use App\Models\{QuestionImport, Survey};
use App\Services\QuestionImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class QuestionImportController
{
    public function create(Request $request, QuestionImportService $service)
    {
        $survey = null;
        if ($request->filled('survey_id')) {
            $request->validate(['survey_id' => 'integer|exists:surveys,id']);
            $survey = Survey::findOrFail($request->integer('survey_id'));
            $service->assertEditable($survey);
        }
        return view('surveys.import', ['survey' => $survey, 'result' => null]);
    }

    public function template(QuestionImportService $service)
    {
        // Build the entire file before emitting download headers.
        $directory = storage_path('app/private/question-imports');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create private import directory.');
        }
        $path = $directory.'/'.Str::random(40).'.xlsx';
        $book = $service->template();
        try {
            (new Xlsx($book))->save($path);
        } catch (\Throwable $e) {
            if (is_file($path)) unlink($path);
            throw $e;
        } finally {
            $book->disconnectWorksheets();
        }
        return response()->download($path, 'TransSurvey_Template_Pertanyaan.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    public function preview(Request $request, QuestionImportService $service)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx|extensions:xlsx|max:5120',
            'survey_id' => 'nullable|integer|exists:surveys,id',
        ]);
        $survey = $request->filled('survey_id') ? Survey::findOrFail($request->integer('survey_id')) : null;
        $metadata = null; $snapshot = null;
        if ($survey) {
            $service->assertEditable($survey);
            $snapshot = $service->snapshot($survey);
        } else {
            $metadata = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:5000',
                'starts_at' => 'required|date_format:Y-m-d',
                'ends_at' => 'required|date_format:Y-m-d|after_or_equal:starts_at',
                'is_template' => 'nullable|boolean',
            ]);
            $metadata['is_template'] = $request->boolean('is_template');
        }
        $result = $service->inspect($request->file('file')->getRealPath(), $survey);
        if ($result['invalid'] || $result['errors']) {
            $request->flashExcept(['file', '_token']);
            return response()->view('surveys.import', compact('survey', 'result'), 422);
        }
        // Drop expired unconfirmed previews belonging to this admin. Never retain the uploaded workbook.
        QuestionImport::where('user_id', $request->user()->id)->whereNull('imported_at')->where('expires_at', '<', now())->delete();
        $batch = QuestionImport::create([
            'token' => Str::random(64), 'user_id' => $request->user()->id, 'survey_id' => $survey?->id,
            'payload' => ['metadata' => $metadata, 'snapshot' => $snapshot, 'rows' => $result['rows']],
            'question_count' => count($result['rows']), 'expires_at' => now()->addMinutes(30),
        ]);
        return redirect()->route('surveys.import.show', $batch->token);
    }

    public function show(Request $request, string $token)
    {
        $batch = $this->owned($request, $token)->firstOrFail();
        if ($batch->imported_at) {
            abort_unless($batch->survey_id && Survey::whereKey($batch->survey_id)->exists(), 404);
            return redirect()->route('surveys.edit', $batch->survey_id);
        }
        if ($batch->expires_at->isPast()) return redirect()->route('surveys.import.create')->withErrors(['file' => __('question_import.expired')]);
        return view('surveys.import-preview', [
            'batch' => $batch,
            'survey' => $batch->payload['snapshot'] !== null ? Survey::findOrFail($batch->survey_id) : null,
        ]);
    }

    public function confirm(Request $request, string $token, QuestionImportService $service)
    {
        $batch = DB::transaction(function () use ($request, $token, $service) {
            $batch = $this->owned($request, $token)->lockForUpdate()->firstOrFail();
            if ($batch->imported_at) {
                abort_unless($batch->survey_id && Survey::whereKey($batch->survey_id)->exists(), 404);
                return $batch; // Retries/double clicks return the original result without inserting again.
            }
            if ($batch->expires_at->isPast()) throw ValidationException::withMessages(['file' => __('question_import.expired')]);
            $payload = $batch->payload;
            if ($payload['snapshot'] !== null) {
                $survey = Survey::lockForUpdate()->findOrFail($batch->survey_id);
                $service->assertEditable($survey);
                if ($service->snapshot($survey) !== $payload['snapshot']) {
                    throw ValidationException::withMessages(['survey' => __('question_import.stale')]);
                }
            } else {
                $survey = Survey::create(array_merge($payload['metadata'], [
                    'status' => 'draft', 'created_by' => $request->user()->id, 'is_demo' => false, 'version' => 1,
                ]));
            }
            if ($survey->questions()->count() + $batch->question_count > QuestionImportService::LIMIT) {
                throw ValidationException::withMessages(['file' => __('question_import.limit')]);
            }
            $position = (int) $survey->questions()->max('position');
            foreach ($payload['rows'] as $row) {
                $survey->questions()->create(array_merge($row['question'], ['position' => ++$position]));
            }
            $survey->increment('version'); // An already-open editor cannot overwrite the appended questions.
            $batch->update(['survey_id' => $survey->id, 'imported_at' => now(), 'payload' => null]);
            return $batch;
        }, 3);
        return redirect()->route('surveys.edit', $batch->survey_id)->with('success', __('question_import.success', ['count' => $batch->question_count]));
    }

    private function owned(Request $request, string $token)
    {
        return QuestionImport::where('token', $token)->where('user_id', $request->user()->id);
    }
}
