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
            'Klien' => $request->filled('client_id') ? Client::findOrFail($request->integer('client_id'))->name : 'Semua klien',
            'Kuesioner' => $request->filled('survey_id') ? Survey::findOrFail($request->integer('survey_id'))->title : 'Semua kuesioner',
            'Kategori' => $request->input('category') ?: 'Semua kategori',
            'Proyek' => $request->input('project') ?: 'Semua proyek',
            'Sumber data' => ['real' => 'Operasional', 'demo' => 'Contoh'][$request->input('source')] ?? 'Semua data',
            'Periode respons' => $this->period($request),
        ];
        $data['clientRows'] = $data['responses']->groupBy('client_id')->map(function ($rows) {
            $score = $rows->whereNotNull('score')->avg('score');
            return [
                'name' => $rows->first()->client?->name ?? 'Klien tidak tersedia',
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
            $from !== null && $to !== null => "$from sampai $to",
            $from !== null => "Mulai $from",
            $to !== null => "Sampai $to",
            default => 'Semua tanggal',
        };
    }

    private function summaryText(array $data): string
    {
        $count = $data['responses']->count();
        if ($count === 0) {
            return '0 respons tercatat pada filter yang dipilih.';
        }
        $average = $data['average'];
        $text = "Terdapat $count respons pada filter yang dipilih. ";
        $text .= $average === null
            ? 'Belum ada jawaban rating yang dapat dihitung.'
            : 'Skor rata-rata '.number_format($average, 2, ',', '.').' dari 5 atau '.number_format($average / 5 * 100, 1, ',', '.').'%.';
        $categories = $data['categories'];
        if ($categories->count() === 1) {
            $text .= ' Kategori '.$categories->keys()->first().' memperoleh skor '.number_format($categories->first(), 2, ',', '.').'.';
        } elseif ($categories->count() > 1) {
            if ($categories->max() === $categories->min()) {
                $text .= ' Seluruh kategori memiliki skor yang sama.';
            } else {
                $best = $categories->filter(fn ($value) => $value == $categories->max())->keys()->implode(', ');
                $lowest = $categories->filter(fn ($value) => $value == $categories->min())->keys()->implode(', ');
                $text .= " Skor kategori tertinggi: $best. Skor kategori terendah: $lowest.";
            }
        }
        return $text;
    }
}
