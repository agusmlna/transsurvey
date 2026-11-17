<?php

namespace App\Services\Csat;

use App\Models\{Survey, User, Workspace};
use Illuminate\Support\Facades\DB;

/**
 * Menyalin satu template CSAT ke workspace lain.
 *
 * Template CSAT harus dimiliki workspace masing-masing supaya laporan antar
 * project tidak tercampur, tetapi isi pertanyaannya cukup diwarisi dari satu
 * template induk.
 */
class CsatTemplateCloner
{
    public function clone(Survey $source, Workspace $workspace, string $title, ?int $createdBy = null): Survey
    {
        $createdBy = $createdBy
            ?: ((int) $source->created_by ?: (int) User::query()->orderBy('id')->value('id'));

        if ($createdBy <= 0) {
            throw new \RuntimeException('Tidak ada user untuk dicatat sebagai pembuat template.');
        }

        return DB::transaction(function () use ($source, $workspace, $title, $createdBy): Survey {
            $survey = new Survey([
                'title' => $title,
                'description' => $source->description,
                'type' => 'csat',
                'status' => 'active',
                'starts_at' => today(),
                'ends_at' => null,
                'is_template' => true,
                'is_demo' => false,
                'version' => 1,
                'created_by' => $createdBy,
            ]);
            $survey->workspace_id = $workspace->id;
            $survey->save();

            foreach ($source->questions()->withoutGlobalScopes()->get() as $question) {
                $survey->questions()->create([
                    'text' => $question->text,
                    'category' => $question->category,
                    'type' => $question->type,
                    'required' => $question->required,
                    'options' => $question->options,
                    'position' => $question->position,
                    'allow_other' => $question->allow_other,
                ]);
            }

            return $survey;
        });
    }
}
