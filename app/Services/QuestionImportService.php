<?php

namespace App\Services;

use App\Models\Survey;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\{Coordinate, DataType, DataValidation};
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Fill};
use ZipArchive;

class QuestionImportService
{
    public const HEADERS = ['Urutan', 'Pertanyaan', 'Kategori', 'Tipe', 'Wajib', 'Pilihan', 'Lainnya'];
    public const LIMIT = 100;

    public function assertEditable(Survey $survey): void
    {
        if ($survey->status !== 'draft' || $survey->invitations()->exists() || $survey->responses()->exists()) {
            throw ValidationException::withMessages(['survey' => __('question_import.locked')]);
        }
    }

    public function snapshot(Survey $survey): string
    {
        return hash('sha256', json_encode([
            $survey->version,
            $survey->questions()->get()->map(fn ($q) => $q->only([
                'id', 'text', 'category', 'type', 'required', 'options', 'allow_other', 'position',
            ]))->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function identity(string $category, string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($category).'|'.trim($text)));
    }

    /** Only the fixed seven columns in the Pertanyaan worksheet are imported. */
    public function inspect(string $path, ?Survey $survey = null): array
    {
        $this->checkArchive($path);
        $reader = new Xlsx();
        $reader->setReadDataOnly(false); // Keep formulas distinguishable; never calculate uploaded cells.
        $reader->setLoadSheetsOnly(['Pertanyaan']);
        try {
            $info = collect($reader->listWorksheetInfo($path))->firstWhere('worksheetName', 'Pertanyaan');
            if (!$info || $info['totalRows'] > 101 || $info['totalColumns'] > 7) {
                throw ValidationException::withMessages(['file' => __('question_import.dimensions')]);
            }
            $book = $reader->load($path);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => __('question_import.unreadable')]);
        }

        try {
            $sheet = $book->getSheetByName('Pertanyaan');
            if (!$sheet || $sheet->getMergeCells()) {
                throw ValidationException::withMessages(['file' => __('question_import.headers')]);
            }
            foreach (self::HEADERS as $i => $name) {
                if ($sheet->getCell([$i + 1, 1])->getValue() !== $name) {
                    throw ValidationException::withMessages(['file' => __('question_import.headers')]);
                }
            }
            $rows = []; $seen = []; $orders = [];
            $existing = $survey ? $survey->questions()->get()->mapWithKeys(
                fn ($q) => [$this->identity($q->category, $q->text) => true]
            )->all() : [];
            for ($line = 2; $line <= $sheet->getHighestDataRow(); $line++) {
                $values = []; $errors = [];
                for ($column = 1; $column <= 7; $column++) {
                    $cell = $sheet->getCell([$column, $line]);
                    $raw = $cell->getValue();
                    if ($raw === null) $raw = '';
                    if ($cell->getDataType() === DataType::TYPE_FORMULA || $cell->getDataType() === DataType::TYPE_ERROR || !is_scalar($raw)) {
                        $errors[] = __('question_import.literal', ['cell' => Coordinate::stringFromColumnIndex($column).$line]);
                        $raw = '[invalid cell]';
                    }
                    $values[] = trim((string) $raw);
                }
                if (implode('', $values) === '') continue;
                [$order, $text, $category, $type, $required, $optionsText, $other] = $values;
                $type = mb_strtolower($type);
                $required = mb_strtolower($required); $other = mb_strtolower($other);
                if (!preg_match('/^[1-9][0-9]{0,2}$/D', $order) || (int) $order > 100) $errors[] = __('question_import.order');
                elseif (isset($orders[(int) $order])) $errors[] = __('question_import.order_duplicate');
                $orders[(int) $order] = true;
                if ($text === '' || mb_strlen($text) > 2000) $errors[] = __('question_import.text');
                if ($category === '' || mb_strlen($category) > 100) $errors[] = __('question_import.category');
                if (!in_array($type, ['rating', 'text', 'choice', 'multiple'], true)) $errors[] = __('question_import.type');
                if (!in_array($required, ['ya', 'tidak'], true)) $errors[] = __('question_import.required');
                if (!in_array($other, ['ya', 'tidak'], true)) $errors[] = __('question_import.other');
                $options = array_values(array_filter(array_map('trim', preg_split('/\R/u', $optionsText)), fn ($v) => $v !== ''));
                if (mb_strlen($optionsText) > 5000) $errors[] = __('question_import.options_length');
                if (in_array($type, ['choice', 'multiple'], true)) {
                    if (count($options) < 2 || count($options) !== count(array_unique($options))) $errors[] = __('question_import.options');
                    if (in_array('__other__', $options, true)) $errors[] = __('question_import.reserved_option');
                } elseif ($optionsText !== '' || $other === 'ya') {
                    $errors[] = __('question_import.no_options');
                }
                $key = $this->identity($category, $text);
                if (isset($seen[$key])) $errors[] = __('question_import.duplicate_file', ['row' => $seen[$key]]);
                if (isset($existing[$key])) $errors[] = __('question_import.duplicate_existing');
                $seen[$key] = $line;
                $rows[] = [
                    'row' => $line, 'order' => (int) $order, 'errors' => array_values(array_unique($errors)),
                    'question' => ['text' => $text, 'category' => $category, 'type' => $type,
                        'required' => $required === 'ya', 'options' => $options, 'allow_other' => $other === 'ya'],
                ];
            }
            $global = [];
            if (!$rows) $global[] = __('question_import.empty');
            if (count($rows) + ($survey?->questions()->count() ?? 0) > self::LIMIT) $global[] = __('question_import.limit');
            usort($rows, fn ($a, $b) => $a['order'] <=> $b['order']);
            return ['rows' => $rows, 'errors' => $global, 'invalid' => count(array_filter($rows, fn ($r) => $r['errors']))];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function checkArchive(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw ValidationException::withMessages(['file' => __('question_import.unreadable')]);
        try {
            $size = 0;
            if ($zip->numFiles > 1000) throw ValidationException::withMessages(['file' => __('question_import.archive_size')]);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $size += $entry['size'];
                if ($size > 20 * 1024 * 1024) throw ValidationException::withMessages(['file' => __('question_import.archive_size')]);
                if (str_contains(strtolower($entry['name']), 'vbaproject') || str_starts_with($entry['name'], 'xl/externalLinks/')) {
                    throw ValidationException::withMessages(['file' => __('question_import.external')]);
                }
            }
        } finally {
            $zip->close();
        }
    }

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('TransSurvey')->setTitle('Template Import Pertanyaan');
        $input = $book->getActiveSheet()->setTitle('Pertanyaan');
        $example = $book->createSheet()->setTitle('Contoh');
        foreach ([$input, $example] as $sheet) {
            $sheet->fromArray(self::HEADERS, null, 'A1');
            $sheet->getStyle('A1:G1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('B91C1C');
            $sheet->getRowDimension(1)->setRowHeight(28);
            $sheet->freezePane('A2');
            foreach ([10, 65, 30, 18, 12, 40, 12] as $i => $width) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($width);
            $sheet->getStyle('A2:G101')->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
            // TEXT keeps pasted question/option text literal (including text beginning with =).
            $sheet->getStyle('B2:G101')->getNumberFormat()->setFormatCode('@');
        }
        for ($row = 2; $row <= 101; $row++) {
            foreach (['D' => 'rating,text,choice,multiple', 'E' => 'Ya,Tidak', 'G' => 'Ya,Tidak'] as $col => $list) {
                $validation = $input->getCell($col.$row)->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setAllowBlank(false)->setShowDropDown(true)->setShowErrorMessage(true)->setFormula1('"'.$list.'"');
            }
        }
        $example->fromArray([
            [1, 'How satisfied are you with our service?', 'Quality of Service', 'rating', 'Ya', '', 'Tidak'],
            [2, 'Which service do you use?', 'Service Information', 'choice', 'Ya', "Contact Center\nLabour Supply", 'Ya'],
            [3, 'Which areas should we improve?', 'Improvement', 'multiple', 'Tidak', "Communication\nTraining\nResponse time", 'Tidak'],
            [4, 'Please share any additional suggestions.', 'Feedback', 'text', 'Tidak', '', 'Tidak'],
        ], null, 'A2');
        for ($row = 2; $row <= 5; $row++) $example->getRowDimension($row)->setRowHeight(62);
        $guide = $book->createSheet()->setTitle('Petunjuk');
        $guide->getColumnDimension('A')->setWidth(28); $guide->getColumnDimension('B')->setWidth(100);
        $guide->fromArray([
            ['TEMPLATE TRANSSURVEY', 'Import pertanyaan tanpa section'],
            ['1. Isi sheet Pertanyaan', 'Sheet Pertanyaan sengaja kosong. Sheet Contoh dan Petunjuk tidak diimpor. Salin contoh bila diperlukan.'],
            ['2. Pertahankan header', 'Nama sheet dan ketujuh header tidak boleh diubah. Satu baris = satu pertanyaan; maksimal 100 pertanyaan termasuk yang sudah tersimpan.'],
            ['Urutan', 'Angka unik 1–100. Baris diurutkan berdasarkan angka ini dan ditambahkan setelah pertanyaan lama.'],
            ['Pertanyaan / Kategori', 'Pertanyaan wajib, maksimal 2000 karakter. Kategori wajib, maksimal 100 karakter. Kategori dipakai laporan; bukan section atau slide.'],
            ['Tipe', 'rating = skala 1–5; text = jawaban teks; choice = pilih satu; multiple = pilih banyak tanpa batas Top 3.'],
            ['Wajib / Lainnya', 'Isi Ya atau Tidak. Lainnya hanya untuk choice atau multiple.'],
            ['Pilihan', 'Untuk choice/multiple: minimal dua opsi berbeda, masing-masing satu baris dalam sel (Alt+Enter di Excel Windows). Maksimal total 5000 karakter. Kosongkan untuk rating/text.'],
            ['Komentar rating rendah', 'Rating 1–3 sudah meminta komentar di form survei. Tidak perlu membuat baris komentar bersyarat terpisah.'],
            ['Batas fitur', 'Section, Top 3, NPS 0–10/1–10, dan kondisi antarpertanyaan belum didukung import ini. Jangan mengubahnya menjadi rating 1–5.'],
            ['Sebelum konfirmasi', 'Upload .xlsx maksimal 5 MB, tanpa formula, macro, atau tautan workbook eksternal. Periksa semua baris pada preview. Preview berlaku 30 menit.'],
            ['Duplikat', 'Teks + kategori yang sama (abaikan kapital dan spasi berlebih) ditolak, baik di file maupun di kuesioner tujuan.'],
            ['Tujuan', 'Buat kuesioner draf baru atau tambah ke draf tanpa undangan/respons. Import tidak mengubah pertanyaan lama dan tidak mengirim email.'],
        ], null, 'A1');
        $guide->getStyle('A1:B13')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $guide->getStyle('A1:B1')->getFont()->setBold(true);
        for ($row = 2; $row <= 13; $row++) $guide->getRowDimension($row)->setRowHeight(46);
        $book->setActiveSheetIndex(0);
        return $book;
    }
}
