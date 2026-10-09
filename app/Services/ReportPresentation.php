<?php

namespace App\Services;

use App\Models\{Client, Survey};
use Illuminate\Http\Request;

class ReportPresentation
{
    public function data(Request $request, ReportService $reports): array
    {
        $data = $reports->summary($request);
        $data['generatedAt'] = now('Asia/Jakarta');
        $data['reportFilters'] = [
            __('Klien') => $request->filled('client_id') ? Client::findOrFail($request->integer('client_id'))->name : __('Semua klien'),
            __('Kuesioner') => $request->filled('survey_id') ? Survey::findOrFail($request->integer('survey_id'))->title : __('Semua kuesioner'),
            __('Kategori') => $request->input('category') ?: __('Semua kategori'),
            __('Proyek') => $request->input('project') ?: __('Semua proyek'),
            __('Sumber data') => ['real' => __('Operasional'), 'demo' => __('Contoh')][$request->input('source')] ?? __('Semua data'),
            __('Periode respons') => $this->period($request),
        ];
        $data['clientRows'] = $data['responses']->groupBy('client_id')->map(function ($rows) {
            $score = $rows->whereNotNull('score')->avg('score');
            return [
                'name' => $rows->first()->client?->name ?? __('Klien tidak tersedia'),
                'project' => $rows->first()->client?->project ?: '-',
                'responses' => $rows->count(), 'score' => $score,
                'percentage' => $score === null ? null : $score / 5,
            ];
        })->sortBy('name')->values();
        $data['summaryText'] = $this->summaryText($data);
        // A fixed local image asset only. Never fetch a URL supplied by a request.
        $logo = public_path(config('transsurvey-reports.logo', 'images/tcid logo.png'));
        $data['reportLogo'] = null;
        if (is_file($logo) && filesize($logo) <= 2 * 1024 * 1024) {
            $mime = @getimagesize($logo)['mime'] ?? null;
            if ($mime === 'image/webp' && function_exists('imagecreatefromstring')) {
                // Some uploaded logos have a .png name but contain WebP bytes.
                // Convert in memory so Dompdf receives a supported image format.
                $image = @imagecreatefromstring(file_get_contents($logo));
                if ($image !== false) {
                    ob_start();
                    imagepng($image);
                    $bytes = ob_get_clean();
                    imagedestroy($image);
                    $data['reportLogo'] = 'data:image/png;base64,'.base64_encode($bytes);
                }
            } elseif (in_array($mime, ['image/png', 'image/jpeg'], true)) {
                $data['reportLogo'] = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($logo));
            }
        }
        return $data;
    }

    private function period(Request $request): string
    {
        $from = $request->filled('from') ? $request->date('from')->format('d/m/Y') : null;
        $to = $request->filled('to') ? $request->date('to')->format('d/m/Y') : null;
        return match (true) {
            $from !== null && $to !== null => __(':from sampai :to', ['from' => $from, 'to' => $to]),
            $from !== null => __('Mulai :from', ['from' => $from]),
            $to !== null => __('Sampai :to', ['to' => $to]),
            default => __('Semua tanggal'),
        };
    }

    private function summaryText(array $data): string
    {
        $count = $data['responses']->count();
        if ($count === 0) {
            return __('0 respons tercatat pada filter yang dipilih.');
        }
        $average = $data['average'];
        $text = __('Terdapat :count respons pada filter yang dipilih. ', ['count' => $count]);
        $text .= $average === null
            ? __('Belum ada jawaban rating yang dapat dihitung.')
            : __('Skor rata-rata :score dari 5 atau :percent%.', ['score' => number_format($average, 2, ',', '.'), 'percent' => number_format($average / 5 * 100, 1, ',', '.')]);
        $categories = $data['categories'];
        if ($categories->count() === 1) {
            $text .= __(' Kategori :category memperoleh skor :score.', ['category' => $categories->keys()->first(), 'score' => number_format($categories->first(), 2, ',', '.')]);
        } elseif ($categories->count() > 1) {
            if ($categories->max() === $categories->min()) {
                $text .= ' '.__('Seluruh kategori memiliki skor yang sama.');
            } else {
                $best = $categories->filter(fn ($value) => $value == $categories->max())->keys()->implode(', ');
                $lowest = $categories->filter(fn ($value) => $value == $categories->min())->keys()->implode(', ');
                $text .= __(' Skor kategori tertinggi: :best. Skor kategori terendah: :lowest.', ['best' => $best, 'lowest' => $lowest]);
            }
        }
        return $text;
    }
}
