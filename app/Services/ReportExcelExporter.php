<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\{Coordinate, DataType};
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\{PageSetup, Worksheet};
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportExcelExporter
{
    public function write(array $data, string $path): void
    {
        $book = $this->workbook($data);
        try {
            (new Xlsx($book))->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function workbook(array $data): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('TransSurvey')->setTitle('Laporan kepuasan klien');
        $summary = $book->getActiveSheet()->setTitle('Ringkasan');
        $this->base($summary, [30, 32, 22, 24, 22]);
        $this->heading($summary, 'Laporan kepuasan klien', 5);
        $row = $this->metadata($summary, $data, 5);
        $summary->mergeCells("A$row:E$row");
        $this->text($summary, "A$row", $data['summaryText']);
        $summary->getStyle("A$row")->getAlignment()->setWrapText(true);
        $summary->getRowDimension($row)->setRowHeight(max(44, 16 * ceil(mb_strlen($data['summaryText']) / 100)));
        $row += 2;
        $this->header($summary, $row++, ['Indikator', 'Nilai', 'Keterangan', '', '']);
        foreach ([
            ['Total respons', $data['responses']->count(), 'Respons pada periode yang dipilih', '0'],
            ['Undangan pada periode', $data['invCount'], 'Berdasarkan tanggal pembuatan undangan', '0'],
            ['Undangan selesai', $data['complete'], 'Dari undangan pada periode yang dipilih', '0'],
            ['Response rate', $data['invCount'] ? $data['rate'] / 100 : null, 'Undangan selesai / undangan pada periode', '0.0%'],
            ['Skor rata-rata', $data['average'], 'Rata-rata skor respons, skala 1-5', '0.00'],
            ['Persentase skor', $data['average'] === null ? null : $data['average'] / 5, 'Skor rata-rata / 5', '0.0%'],
        ] as [$label, $value, $note, $format]) {
            $this->text($summary, "A$row", $label);
            $this->number($summary, "B$row", $value, $format);
            $summary->mergeCells("C$row:E$row");
            $this->text($summary, "C$row", $note);
            $this->stripe($summary, $row++, 5);
        }
        $row += 2;
        $this->header($summary, $row++, ['Klien', 'Proyek', 'Respons', 'Skor / 5', 'Persentase']);
        foreach ($data['clientRows'] as $client) {
            $this->text($summary, "A$row", $client['name']);
            $this->text($summary, "B$row", $client['project']);
            $this->number($summary, "C$row", $client['responses'], '0');
            $this->number($summary, "D$row", $client['score'], '0.00');
            $this->number($summary, "E$row", $client['percentage'], '0.0%');
            $summary->getRowDimension($row)->setRowHeight(max(32, 15 * ceil(max(mb_strlen($client['name']), mb_strlen($client['project'])) / 27)));
            $this->stripe($summary, $row++, 5);
        }
        if ($data['clientRows']->isEmpty()) {
            $this->text($summary, 'A'.$row++, 'Belum ada respons.');
        }
        $row += 2;
        $this->header($summary, $row++, ['Kategori', 'Skor / 5', 'Persentase', '', '']);
        foreach ($data['categories'] as $name => $score) {
            $this->text($summary, "A$row", $name);
            $this->number($summary, "B$row", $score, '0.00');
            $this->number($summary, "C$row", $score / 5, '0.0%');
            $summary->getRowDimension($row)->setRowHeight(max(30, 15 * ceil(mb_strlen($name) / 27)));
            $this->stripe($summary, $row++, 5);
        }
        if ($data['categories']->isEmpty()) {
            $this->text($summary, 'A'.$row++, 'Belum ada jawaban rating.');
        }
        $row += 2;
        $summary->mergeCells("A$row:E$row");
        $this->text($summary, "A$row", 'Skor keseluruhan = rata-rata skor respons. Skor kategori = rata-rata jawaban rating dalam kategori. Teks dan pilihan ganda tidak dihitung. Tanggal respons dan tanggal pembuatan undangan dapat berbeda.');
        $summary->getRowDimension($row)->setRowHeight(48);
        // $summary->freezePane('A15');
        $summary->getPageSetup()->setPrintArea("A1:E$row");

        $detail = $book->createSheet()->setTitle('Detail Jawaban');
        $this->base($detail, [14, 27, 24, 30, 23, 14, 16, 25, 18, 58, 42, 58]);
        $this->heading($detail, 'Detail jawaban survei', 12);
        $row = $this->metadata($detail, $data, 12);
        $detail->mergeCells("A$row:L$row");
        $this->text($detail, "A$row", 'Satu baris mewakili satu jawaban. ID respons yang sama berarti jawaban berasal dari satu pengisian survei. Tanggal menggunakan WIB.');
        $detail->getRowDimension($row)->setRowHeight(28);
        $row += 2;
        $headerRow = $row;
        $this->header($detail, $row++, ['ID Respons', 'Klien', 'Proyek', 'Kuesioner', 'Tanggal respons (WIB)', 'Skor respons', 'Persentase skor', 'Kategori', 'Jenis pertanyaan', 'Pertanyaan', 'Jawaban', 'Komentar']);
        foreach ($data['responses'] as $response) {
            // Responses without answers remain represented; do not silently lose them.
            $answers = $response->answers->isEmpty() ? [null] : $response->answers;
            foreach ($answers as $answer) {
                $cells = [
                    'A' => (string) $response->id, 'B' => $response->client?->name,
                    'C' => $response->client?->project, 'D' => $response->survey?->title,
                    'H' => $answer?->category, 'I' => ['rating' => 'Skala 1-5', 'choice' => 'Pilihan ganda', 'text' => 'Teks'][$answer?->type] ?? ($answer?->type ?? ''), 'J' => $answer?->question_text,
                    'K' => $answer?->value, 'L' => $answer?->comment,
                ];
                foreach ($cells as $column => $value) {
                    // All user content is explicitly text: =, +, -, @ never become formulas.
                    $this->text($detail, "$column$row", $value);
                }
                if ($response->submitted_at !== null) {
                    $date = $response->submitted_at->copy()->timezone('Asia/Jakarta');
                    $this->number($detail, "E$row", Date::PHPToExcel($date), 'dd/mm/yyyy hh:mm');
                }
                $this->number($detail, "F$row", $response->score, '0.00');
                $this->number($detail, "G$row", $response->score === null ? null : $response->score / 5, '0.0%');
                if ($answer?->type === 'rating' && in_array((string) $answer->value, ['1','2','3','4','5'], true)) {
                    $this->number($detail, "K$row", (int) $answer->value, '0');
                }
                $length = max(mb_strlen((string) $answer?->question_text), mb_strlen((string) $answer?->value), mb_strlen((string) $answer?->comment));
                $detail->getRowDimension($row)->setRowHeight(min(409, max(34, 15 * ceil($length / 38))));
                $this->stripe($detail, $row++, 12);
            }
        }
        $detail->setAutoFilter("A$headerRow:L".max($headerRow, $row - 1));
        // $detail->freezePane('C'.($headerRow + 1));
        if ($row === $headerRow + 1) {
            $detail->mergeCells("A$row:L$row");
            $this->text($detail, "A$row", 'Belum ada respons yang sesuai dengan filter laporan ini.');
        }
        $detail->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $detail->getPageSetup()->setPrintArea("A1:L$row");
        $book->setActiveSheetIndex(0);
        return $book;
    }

    private function base(Worksheet $sheet, array $widths): void
    {
        $sheet->setShowGridlines(false);
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet->getDefaultRowDimension()->setRowHeight(25);
        foreach ($widths as $i => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($width);
        }
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getHeaderFooter()->setOddFooter('&LTransSurvey&RHalaman &P / &N');
    }

    private function heading(Worksheet $sheet, string $title, int $columns): void
    {
        $end = Coordinate::stringFromColumnIndex($columns);
        $sheet->mergeCells("A1:{$end}1");
        $this->text($sheet, 'A1', 'TRANSSURVEY');
        $sheet->getStyle("A1:{$end}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFB80F35');
        $sheet->getStyle('A1')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'))->setBold(true)->setSize(12);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->mergeCells("A3:{$end}3");
        $this->text($sheet, 'A3', $title);
        $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(20);
        $sheet->getRowDimension(3)->setRowHeight(35);
    }

    private function metadata(Worksheet $sheet, array $data, int $columns): int
    {
        $end = Coordinate::stringFromColumnIndex($columns);
        $row = 5;
        foreach ($data['reportFilters'] + ['Dibuat (WIB)' => $data['generatedAt']->format('d/m/Y H:i')] as $label => $value) {
            $this->text($sheet, "A$row", $label);
            $sheet->mergeCells("B$row:$end$row");
            $this->text($sheet, "B$row", $value);
            $sheet->getStyle("B$row")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(max(25, 15 * ceil(mb_strlen((string) $value) / ($columns === 5 ? 80 : 150))));
            $row++;
        }
        return $row + 1;
    }

    private function text(Worksheet $sheet, string $cell, mixed $value): void
    {
        $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
        $sheet->getStyle($cell)->getAlignment()->setIndent(1);
    }

    private function number(Worksheet $sheet, string $cell, mixed $value, string $format): void
    {
        if ($value === null) {
            $this->text($sheet, $cell, '-');
            return;
        }
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setIndent(1);
    }

    private function header(Worksheet $sheet, int $row, array $labels): void
    {
        foreach ($labels as $i => $label) {
            $this->text($sheet, Coordinate::stringFromColumnIndex($i + 1).$row, $label);
        }
        $end = Coordinate::stringFromColumnIndex(count($labels));
        $style = $sheet->getStyle("A$row:$end$row");
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF253247');
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(34);
    }

    private function stripe(Worksheet $sheet, int $row, int $columns): void
    {
        $end = Coordinate::stringFromColumnIndex($columns);
        $style = $sheet->getStyle("A$row:$end$row");
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setARGB('FFE2E8F0');
        if ($row % 2 === 0) {
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF5F7FA');
        }
    }
}
