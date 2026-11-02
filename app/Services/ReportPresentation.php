<?php

namespace App\Services;

use App\Models\{Client, Survey};
use Illuminate\Http\Request;

class ReportPresentation
{
    public function data(Request $request, ReportService $reports): array
    {
        $data = $reports->summary($request);
        $pic = $request->filled('client_id') ? Client::findOrFail($request->integer('client_id')) : null;
        $data['generatedAt'] = now('Asia/Jakarta');
        $data['reportFilters'] = [
            __('Klien') => $pic?->name ?? ($request->input('client') ?: __('Semua klien')),
            __('PIC') => $pic ? $this->picLabel($pic) : __('Semua PIC'),
            __('Kuesioner') => $request->filled('survey_id') ? Survey::findOrFail($request->integer('survey_id'))->title : __('Semua kuesioner'),
            __('Kategori') => $request->input('category') ?: __('Semua kategori'),
            __('Proyek') => $request->input('project') ?: __('Semua proyek'),
            __('Sumber data') => ['real' => __('Operasional'), 'demo' => __('Contoh')][$request->input('source')] ?? __('Semua data'),
            __('Periode respons') => $this->period($request),
        ];
        // One row per client name: a company with several PIC rows is shown once,
        // including PICs that were invited but have not responded yet.
        $stats = $data['invitationStats'];
        $responsesByClient = $data['responses']->groupBy('client_id');
        $clientIds = $stats->keys()->merge($responsesByClient->keys())->unique();
        $data['clientRows'] = Client::whereIn('id', $clientIds)->get()
            ->groupBy(fn ($client) => mb_strtolower(trim($client->name)))
            ->map(function ($clients) use ($stats, $responsesByClient) {
                $rows = $clients->flatMap(fn ($client) => $responsesByClient->get($client->id, collect()));
                $score = $rows->whereNotNull('score')->avg('score');
                $sent = $clients->sum(fn ($client) => (int) ($stats[$client->id]->sent ?? 0));
                $completed = $clients->sum(fn ($client) => (int) ($stats[$client->id]->completed ?? 0));
                return [
                    'name' => $clients->first()->name,
                    'project' => $clients->pluck('project')->filter()->unique()->implode(', ') ?: '-',
                    'pic' => $clients->map(fn ($client) => $this->picLabel($client))->unique()->implode(', ') ?: '-',
                    'sent' => $sent, 'completed' => $completed, 'pending' => max(0, $sent - $completed),
                    'responses' => $rows->count(), 'score' => $score,
                    'percentage' => $score === null ? null : $score / 5,
                ];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
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

    public function picLabel(Client $client): string
    {
        return trim((string) $client->contact) ?: (string) $client->email;
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
