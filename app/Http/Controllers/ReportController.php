<?php

namespace App\Http\Controllers;

use App\Models\{Client, Survey};
use App\Services\{ReportService, ReportPresentation, ReportPdfExporter, ReportExcelExporter};
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReportController
{
    public function index(Request $r, ReportService $reports, ReportPresentation $presentation)
    {
        $reports->validateFilters($r);
        return view('reports.index', $presentation->data($r, $reports) + ['clients' => Client::orderBy('name')->get(), 'surveys' => Survey::latest()->get()]);
    }
    public function csv(Request $r, ReportService $reports)
    {
        $reports->validateFilters($r);
        return response()->streamDownload(function () use ($r, $reports) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Klien', 'Proyek', 'Kuesioner', 'Tanggal', 'Skor', 'Persentase', 'Kategori', 'Pertanyaan', 'Jawaban', 'Komentar'], ',', '"', '');
            $reports->responses($r)->orderBy('id')->chunkById(100, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    foreach ($row->answers as $a) {
                        $cells = [$row->client->name, $row->client->project, $row->survey->title, $row->submitted_at->toIso8601String(), $row->score, $row->score === null ? '' : round($row->score / 5 * 100, 2), $a->category, $a->question_text, $a->value, $a->comment];
                        $cells = array_map(function ($v) {
                            $s = (string)$v;
                            return preg_match('/^[=+\-@\t\r\n]/', $s) ? "'" . $s : $s;
                        }, $cells);
                        fputcsv($out, $cells, ',', '"', '');
                    }
                }
            });
            fclose($out);
        }, 'laporan-transsurvey-' . today()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(Request $r, ReportService $reports, ReportPresentation $presentation, ReportPdfExporter $exporter)
    {
        $reports->validateFilters($r);
        $data = $presentation->data($r, $reports);
        $name = $this->fileName($data, 'pdf');

        return response($exporter->render($data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(
                'attachment',
                $name,
                str_replace('%', '', Str::ascii($name))
            ),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function xlsx(Request $r, ReportService $reports, ReportPresentation $presentation, ReportExcelExporter $exporter)
    {
        $reports->validateFilters($r);
        $data = $presentation->data($r, $reports);
        // Build in a private temporary file before sending headers; failures never yield a corrupt download.
        $tmpDir = storage_path('app/dompdf-tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $path = tempnam($tmpDir, 'transsurvey-');
        if ($path === false) {
            throw new \RuntimeException('Tidak dapat membuat file sementara laporan.');
        }
        try {
            $exporter->write($data, $path);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        return response()->download($path, $this->fileName($data, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    private function fileName(array $data, string $extension): string
    {
        $filters = $data['reportFilters'];

        $name = collect([
            'Laporan TransSurvey',
            $filters['Klien'] ?? null,
            $filters['Kuesioner'] ?? null,
            $filters['Periode respons'] ?? null,
        ])
            ->map(fn ($part) => $this->cleanName((string) $part))
            ->filter()
            ->implode(' - ');

        return Str::limit($name, 150, '') . '.' . $extension;
    }

    private function cleanName(string $value): string
    {
        $value = str_replace(['/', '\\'], '-', $value);          // tanggal 01/09/2026 -> 01-09-2026
        $value = preg_replace('/[:*?"<>|\x00-\x1F]/u', '', $value); // karakter terlarang di Windows
        $value = preg_replace('/\s+/u', ' ', $value);             // rapikan spasi ganda

        return trim($value, " .-");
    }
}