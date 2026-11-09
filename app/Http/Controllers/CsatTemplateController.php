<?php

namespace App\Http\Controllers;

use App\Models\{BankQuestion, Survey, Workspace};
use App\Services\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CSAT templates are ordinary surveys with type "csat". They have no start/end
 * date because a template is reused for every ticket; the link expiry is a
 * workspace setting instead.
 */
class CsatTemplateController
{
    public function index()
    {
        $workspace = $this->workspace();
        $templates = Survey::query()
            ->where('type', 'csat')
            ->withCount('questions')
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('csat.index', [
            'templates' => $templates,
            'workspace' => $workspace,
            'defaultId' => (int) $workspace->default_csat_survey_id,
            'expiryLabel' => $this->expiryLabel($workspace),
        ]);
    }

    public function create()
    {
        return $this->editor(new Survey([
            'type' => 'csat',
            'status' => 'draft',
            'starts_at' => today(),
            'ends_at' => null,
            'is_template' => true,
        ]));
    }

    public function store(Request $request)
    {
        $workspace = $this->workspace();

        $template = DB::transaction(function () use ($request, $workspace) {
            $template = $this->persist($request, new Survey([
                'type' => 'csat',
                'created_by' => $request->user()->id,
            ]));

            // The first template in a workspace becomes its default automatically.
            if (!$workspace->default_csat_survey_id) {
                $workspace->default_csat_survey_id = $template->id;
                $workspace->save();
            }

            return $template;
        });

        return redirect()
            ->route('csat.templates.edit', $template)
            ->with('success', __('Template CSAT berhasil dibuat.'));
    }

    public function edit(Survey $survey)
    {
        return $this->editor($this->template($survey));
    }

    public function update(Request $request, Survey $survey)
    {
        $this->template($survey);

        DB::transaction(function () use ($request, $survey) {
            $locked = Survey::lockForUpdate()->findOrFail($survey->id);

            if ($locked->version !== $request->integer('version')) {
                throw ValidationException::withMessages([
                    'survey' => __('Template sudah berubah. Muat ulang sebelum menyimpan.'),
                ]);
            }

            $this->persist($request, $locked);
        });

        return back()->with('success', __('Template CSAT berhasil disimpan.'));
    }

    public function duplicate(Request $request, Survey $survey)
    {
        $this->template($survey);

        $copy = DB::transaction(function () use ($request, $survey) {
            $copy = $survey->replicate();
            $copy->title = mb_substr($survey->title, 0, 240) . ' (salinan)';
            $copy->status = 'draft';
            $copy->type = 'csat';
            $copy->is_template = true;
            $copy->is_demo = false;
            $copy->starts_at = today();
            $copy->ends_at = null;
            $copy->created_by = $request->user()->id;
            $copy->version = 1;
            $copy->save();

            foreach ($survey->questions as $question) {
                $copy->questions()->create($question->only([
                    'text', 'category', 'type', 'required', 'options', 'allow_other', 'position',
                ]));
            }

            return $copy;
        });

        return redirect()
            ->route('csat.templates.edit', $copy)
            ->with('success', __('Salinan template dibuat. Silakan sesuaikan pertanyaannya.'));
    }

    public function setDefault(Survey $survey)
    {
        $this->template($survey);

        $workspace = $this->workspace();
        $workspace->default_csat_survey_id = $survey->id;
        $workspace->save();

        return back()->with('success', __('Template ini sekarang menjadi default workspace.'));
    }

    public function destroy(Survey $survey)
    {
        $this->template($survey);

        if ($survey->invitations()->exists()) {
            throw ValidationException::withMessages([
                'template' => __('Template yang sudah dipakai tidak dapat dihapus. Duplikat dulu bila ingin mengubahnya.'),
            ]);
        }

        DB::transaction(function () use ($survey) {
            $workspace = $this->workspace();

            if ((int) $workspace->default_csat_survey_id === $survey->id) {
                $workspace->default_csat_survey_id = null;
                $workspace->save();
            }

            $survey->questions()->delete();
            $survey->delete();
        });

        return redirect()
            ->route('csat.templates.index')
            ->with('success', __('Template CSAT dihapus.'));
    }

    public function preview(Survey $survey)
    {
        $this->template($survey);

        return view('public.survey', [
            'survey' => $survey->load('questions'),
            'invitation' => null,
            'preview' => true,
            'closed' => false,
        ]);
    }

    private function editor(Survey $survey)
    {
        return view('surveys.form', [
            'survey' => $survey->load('questions'),
            'bank' => BankQuestion::orderBy('category')->get(),
            'locked' => false,
            'csat' => true,
        ]);
    }

    private function persist(Request $request, Survey $survey): Survey
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'status' => 'required|in:draft,active,closed',
        ]);

        $request->validate([
            'questions' => 'required|array|min:1|max:100',
            'questions.*.text' => 'required|string|max:2000',
            'questions.*.category' => 'required|string|max:100',
            'questions.*.type' => 'required|in:rating,choice,text,multiple,date,email,name,company',
            'questions.*.required' => 'nullable|boolean',
            'questions.*.options' => 'nullable|string|max:5000',
            'questions.*.allow_other' => 'nullable|boolean',
        ]);

        $questions = [];

        foreach (array_values($request->input('questions')) as $index => $question) {
            $options = array_values(array_filter(
                array_map('trim', preg_split('/\R/', $question['options'] ?? '')),
                fn ($option) => $option !== ''
            ));

            if (in_array($question['type'], ['choice', 'multiple'], true)
                && (count($options) < 2 || count($options) !== count(array_unique($options)))) {
                throw ValidationException::withMessages([
                    'questions' => __('Pilihan ganda memerlukan minimal dua opsi yang berbeda.'),
                ]);
            }

            $questions[] = [
                'text' => $question['text'],
                'category' => $question['category'],
                'type' => $question['type'],
                'required' => filter_var($question['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'options' => in_array($question['type'], ['choice', 'multiple'], true) ? $options : [],
                'position' => $index + 1,
                'allow_other' => in_array($question['type'], ['choice', 'multiple'], true)
                    && filter_var($question['allow_other'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        $survey->fill($data);
        $survey->type = 'csat';
        $survey->is_template = true;
        $survey->ends_at = null;
        $survey->starts_at ??= today();
        $survey->version = ($survey->version ?? 0) + 1;
        $survey->save();

        $survey->questions()->delete();
        $survey->questions()->createMany($questions);

        return $survey;
    }

    private function template(Survey $survey): Survey
    {
        abort_unless(($survey->type ?? 'client') === 'csat', 404);

        return $survey;
    }

    private function workspace(): Workspace
    {
        return Workspace::findOrFail(app(CurrentWorkspace::class)->filterId());
    }

    private function expiryLabel(Workspace $workspace): string
    {
        $csat = $workspace->settings['csat'] ?? [];
        $unit = $csat['expiry_unit'] ?? null;
        $value = (int) ($csat['expiry_value'] ?? 0);

        if ($unit === 'unlimited' || $unit === null) {
            return $unit === 'unlimited' ? __('Tanpa batas waktu') : __('Belum diatur');
        }

        $labels = [
            'minutes' => __('menit'),
            'hours' => __('jam'),
            'days' => __('hari'),
        ];

        return $value . ' ' . ($labels[$unit] ?? $unit);
    }
}
