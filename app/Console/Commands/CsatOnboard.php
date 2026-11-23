<?php

namespace App\Console\Commands;

use App\Models\{Survey, Workspace};
use App\Services\Csat\CsatTemplateCloner;
use Illuminate\Console\Command;

/**
 * Siapkan workspace CSAT untuk daftar project TransCXP.
 *
 * Setiap project mendapat workspace dengan kode = id_project, satu salinan
 * template CSAT (supaya laporan tidak tercampur), dan masa berlaku tautan.
 * Template baru hanya diaktifkan sebagai default kalau workspace belum punya,
 * atau bila opsi --activate/--force dipakai.
 */
class CsatOnboard extends Command
{
    protected $signature = 'cxp:csat-onboard
        {--projects= : Daftar kode project dipisah koma (default semua dari config)}
        {--from= : ID template CSAT sumber}
        {--template= : Judul template baru; :name dan :code diganti otomatis}
        {--expiry-days=7 : Masa berlaku tautan CSAT dalam hari}
        {--pilot : Hanya project pilot (CXP_CSAT_PILOT_PROJECTS) dan aktifkan autosend}
        {--activate : Jadikan template hasil clone sebagai default}
        {--force : Clone ulang dan jadikan default walaupun workspace sudah punya template}
        {--sync-names : Samakan nama workspace dengan daftar project}
        {--dry-run : Tampilkan rencana tanpa menulis database}';

    protected $description = 'Buat workspace dan template CSAT untuk project TransCXP.';

    public function handle(CsatTemplateCloner $cloner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $pilot = (bool) $this->option('pilot');

        if ($pilot) {
            $pilotCodes = array_filter(array_map(
                static fn ($code): string => trim((string) $code),
                (array) config('cxp.csat.pilot_projects', []),
            ), static fn (string $code): bool => $code !== '');

            if ($pilotCodes === []) {
                $this->error('CXP_CSAT_PILOT_PROJECTS masih kosong. Isi 2-3 kode project pilot terlebih dahulu.');

                return self::FAILURE;
            }
        }

        $projects = $this->projects();

        if ($projects === []) {
            return self::FAILURE;
        }

        $source = $this->source();

        if ($source === null) {
            $this->error('Template CSAT sumber tidak ditemukan. Buat satu template CSAT dahulu atau isi --from=<id>.');

            return self::FAILURE;
        }

        $expiryDays = max(1, (int) ($this->option('expiry-days') ?: 7));
        $titleTemplate = trim((string) $this->option('template')) ?: 'CSAT :name';

        $this->line('Template sumber : #'.$source->id.' '.$source->title);
        $this->line('Project         : '.count($projects));
        $this->line('Masa berlaku    : '.$expiryDays.' hari');
        $this->line('Mode            : '.($dryRun ? 'DRY-RUN (tidak menulis database)' : 'TULIS'));
        $this->newLine();

        $rows = [];
        $created = 0;
        $cloned = 0;

        foreach ($projects as $code => $name) {
            $workspace = Workspace::query()
                ->whereRaw('lower(code) = ?', [mb_strtolower((string) $code)])
                ->first();

            $isNew = $workspace === null;

            if ($isNew && ! $dryRun) {
                $workspace = Workspace::create([
                    'name' => $name,
                    'code' => (string) $code,
                ]);
                $created++;
            }

            if ($workspace === null && $dryRun) {
                $rows[] = [$code, $name, 'workspace baru', $this->title($titleTemplate, (string) $code, $name), 'dry-run'];

                continue;
            }

            if ($this->option('sync-names') && $workspace->name !== $name && ! $dryRun) {
                $workspace->name = $name;
            }

            $survey = null;

            if ($workspace->default_csat_survey_id) {
                $survey = Survey::withoutGlobalScopes()
                    ->where('type', 'csat')
                    ->find($workspace->default_csat_survey_id);
            }

            $action = 'tetap';

            if ($survey === null || $this->option('force')) {
                if ($dryRun) {
                    $action = 'clone';
                    $survey = $source;
                } else {
                    $survey = $cloner->clone($source, $workspace, $this->title($titleTemplate, (string) $code, $name));
                    $cloned++;
                    $action = 'clone';
                }
            }

            $settings = $workspace->settings ?? [];
            $csat = is_array($settings['csat'] ?? null) ? $settings['csat'] : [];
            $csat['expiry_unit'] = 'days';
            $csat['expiry_value'] = $expiryDays;

            if ($pilot) {
                // --pilot dengan --projects: hanya project pilot yang autosend.
                $csat['autosend'] = in_array((string) $code, array_map('strval', (array) config('cxp.csat.pilot_projects', [])), true);
            } elseif (! array_key_exists('autosend', $csat)) {
                $csat['autosend'] = false;
            }

            $settings['csat'] = $csat;

            if (! $dryRun) {
                $workspace->settings = $settings;

                if (! $workspace->default_csat_survey_id || $this->option('activate') || $this->option('force')) {
                    $workspace->default_csat_survey_id = $survey?->id;
                }

                $workspace->save();
            }

            $rows[] = [
                $code,
                $name,
                $action,
                '#'.($survey?->id ?? '-').' '.($survey?->title ?? '-'),
                $dryRun ? 'dry-run' : 'ok',
            ];
        }

        $this->table(['Kode', 'Nama', 'Aksi', 'Template', 'Status'], $rows);

        if ($dryRun) {
            $this->comment('Dry-run selesai. Jalankan tanpa --dry-run untuk menerapkan.');

            return self::SUCCESS;
        }

        $this->info('Selesai: '.$created.' workspace baru, '.$cloned.' template di-clone.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function projects(): array
    {
        $defaults = [];

        foreach ((array) config('cxp.csat.projects', []) as $code => $name) {
            if (trim((string) $code) !== '') {
                $defaults[(string) $code] = trim((string) $name);
            }
        }

        $option = trim((string) $this->option('projects'));

        if ($option !== '') {
            $codes = array_values(array_filter(array_map(
                static fn ($code): string => trim((string) $code),
                explode(',', $option),
            ), static fn (string $code): bool => $code !== ''));
        } elseif ($this->option('pilot')) {
            $codes = array_values(array_filter(array_map(
                static fn ($code): string => trim((string) $code),
                (array) config('cxp.csat.pilot_projects', []),
            ), static fn (string $code): bool => $code !== ''));
        } else {
            $codes = array_keys($defaults);
        }

        $disabled = array_values(array_filter(array_map(
            static fn ($code): string => trim((string) $code),
            (array) config('cxp.csat.disabled_projects', []),
        ), static fn (string $code): bool => $code !== ''));
        $codes = array_values(array_filter($codes, static fn (string $code): bool => ! in_array($code, $disabled, true)));

        if ($this->option('pilot') && $codes === []) {
            $this->error('CXP_CSAT_PILOT_PROJECTS masih kosong. Isi 2-3 kode project pilot terlebih dahulu.');

            return [];
        }

        $projects = [];

        foreach ($codes as $code) {
            $code = (string) $code;
            $projects[$code] = $defaults[$code] ?? ('Project '.$code);
        }

        return $projects;
    }

    private function source(): ?Survey
    {
        $id = (int) ($this->option('from') ?: config('cxp.csat.master_template_id', 0));

        if ($id > 0) {
            $survey = Survey::withoutGlobalScopes()->where('type', 'csat')->find($id);

            if ($survey !== null) {
                return $survey;
            }
        }

        $legacy = Workspace::query()->where('is_legacy', true)->first();

        if ($legacy?->default_csat_survey_id) {
            $survey = Survey::withoutGlobalScopes()
                ->where('type', 'csat')
                ->find($legacy->default_csat_survey_id);

            if ($survey !== null) {
                return $survey;
            }
        }

        return Survey::withoutGlobalScopes()
            ->where('type', 'csat')
            ->orderByDesc('is_template')
            ->orderBy('id')
            ->first();
    }

    private function title(string $template, string $code, string $name): string
    {
        return str_replace([':name', ':code'], [$name, $code], $template);
    }
}
