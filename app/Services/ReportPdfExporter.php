<?php

namespace App\Services;

use Dompdf\{Dompdf, Options};

class ReportPdfExporter
{
    public function render(array $data): string
    {
        $tmpDir = storage_path('app/dompdf-tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', public_path());
        $options->set('tempDir', $tmpDir);

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml(view('reports.pdf', $data)->render(), 'UTF-8');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');

        $canvas->page_text(36, 811, 'TransSurvey | PT Transcosmos Indonesia', $font, 8, [0.40, 0.44, 0.49]);
        $canvas->page_text(471, 811, 'Halaman {PAGE_NUM}/{PAGE_COUNT}', $font, 8, [0.40, 0.44, 0.49]);

        return $pdf->output();
    }
}