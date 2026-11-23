<?php

namespace Tests\Feature;

use App\Services\Cxp\CxpWhatsAppAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Artisan, Http};
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Sinkronisasi nomor pengirim dari /api/v1/accountWaProject/{id_project}.
 *
 * Test memakai Http::fake: tidak ada kredensial asli dan tidak ada request
 * sungguhan ke TransCXP.
 */
class CxpWaAccountsSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    /** @var array<string, mixed> */
    private array $accountResponse;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $this->file = $dir.DIRECTORY_SEPARATOR.'cxp-wa-accounts-'.Str::uuid()->toString().'.json';

        $this->accountResponse = [
            'status' => true,
            'message' => 'Data account WhatsApp berhasil diambil.',
            'data' => [
                [
                    'id' => 95,
                    'id_project' => 23,
                    'source' => 'whatsapp',
                    'active' => 'yes',
                    'whatsapp_number' => '628111660770',
                    'whatsapp_name' => 'Panasonic',
                ],
            ],
        ];

        config([
            'cxp.api.base_url' => 'https://cxp.example.test',
            'cxp.api.email' => 'api-user@example.test',
            'cxp.api.password' => 'password-rahasia',
            'cxp.api.timeout' => 5,
            'cxp.api.login_field' => 'email',
            'cxp.api.auth_header' => 'Authorization',
            'cxp.api.auth_prefix' => 'Bearer',
            'cxp.whatsapp.base_url' => 'https://wa.example.test',
            'cxp.whatsapp.account_base_url' => 'https://wa.example.test',
            'cxp.whatsapp.account_path' => '/api/v1/accountWaProject/{id_project}',
            'cxp.whatsapp.accounts' => null,
            'cxp.whatsapp.accounts_file' => $this->file,
            'cxp.csat.projects' => ['23' => 'Panasonic'],
        ]);

        $this->writeAccounts([
            '23' => [
                'username' => 'wa-user-23',
                'password' => 'wa-password-23',
                'number' => '+620000000000',
            ],
            '6' => [
                'username' => 'wa-user-6',
                'password' => 'wa-password-6',
                'number' => '+6281110877088',
            ],
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/api/v1/login-user')) {
                return Http::response(['userInfo' => ['token' => 'token-uji']], 200);
            }

            if (str_contains($request->url(), '/api/v1/accountWaProject/')) {
                return Http::response($this->accountResponse, 200);
            }

            return Http::response(['status' => false], 404);
        });
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function test_sync_writes_sender_number_and_account_id_without_touching_credentials(): void
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-wa-accounts-sync', [], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exitCode, $text);

        $accounts = $this->readAccounts();

        $this->assertSame('+628111660770', $accounts['23']['number']);
        $this->assertSame('95', $accounts['23']['account_id']);
        $this->assertSame('wa-user-23', $accounts['23']['username']);
        $this->assertSame('wa-password-23', $accounts['23']['password']);

        // Workspace lain tidak berubah.
        $this->assertSame('+6281110877088', $accounts['6']['number']);
        $this->assertArrayNotHasKey('account_id', $accounts['6']);

        // File bisa langsung dipakai loader akun.
        $loaded = CxpWhatsAppAccounts::fromConfig()->forWorkspace('23');
        $this->assertNotNull($loaded);
        $this->assertSame('95', $loaded['account_id']);

        $this->assertStringNotContainsString('password-rahasia', $text);
    }

    public function test_disabled_projects_are_not_synced_even_when_requested(): void
    {
        config(['cxp.csat.disabled_projects' => ['23']]);

        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-wa-accounts-sync', ['--projects' => '23,6'], $output);

        $this->assertSame(0, $exitCode, $output->fetch());

        $accounts = $this->readAccounts();
        $this->assertSame('+620000000000', $accounts['23']['number']);
        $this->assertArrayNotHasKey('account_id', $accounts['23']);
        $this->assertSame('+628111660770', $accounts['6']['number']);
    }

    public function test_dry_run_does_not_write_file(): void
    {
        $before = (string) file_get_contents($this->file);

        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-wa-accounts-sync', ['--dry-run' => true], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exitCode, $text);
        $this->assertSame($before, (string) file_get_contents($this->file));
        $this->assertStringContainsString('Dry-run', $text);
    }

    public function test_sync_skips_project_without_active_whatsapp_account(): void
    {
        $this->accountResponse = [
            'status' => true,
            'data' => [[
                'id' => 95,
                'source' => 'whatsapp',
                'active' => 'no',
                'whatsapp_number' => '628111660770',
            ]],
        ];

        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-wa-accounts-sync', [], $output);

        $this->assertSame(0, $exitCode);
        $this->assertSame('+620000000000', $this->readAccounts()['23']['number']);
        $this->assertStringContainsString('dilewati', $output->fetch());
    }

    public function test_sync_writes_all_active_channels_and_ignores_inactive_accounts(): void
    {
        $this->accountResponse = [
            'status' => true,
            'data' => [
                [
                    'id' => 110,
                    'source' => 'whatsapp',
                    'active' => 'yes',
                    'whatsapp_number' => '6281113900527',
                    'whatsapp_name' => 'Berani Berencana',
                ],
                [
                    'id' => 20,
                    'source' => 'whatsapp',
                    'active' => 'no',
                    'whatsapp_number' => '62811139005271',
                    'whatsapp_name' => 'Berani Berencana Lama',
                ],
                [
                    'id' => 107,
                    'source' => 'whatsapp',
                    'active' => 'yes',
                    'whatsapp_number' => '6281119726459',
                    'whatsapp_name' => 'DKT News',
                ],
            ],
        ];

        $this->writeAccounts([
            '9' => [
                'username' => 'dkt@trans-cosmos.co.id',
                'password' => 'rahasia-9',
                'number' => '+620000000000',
            ],
        ]);

        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-wa-accounts-sync', ['--projects' => '9'], $output);

        $this->assertSame(0, $exitCode, $output->fetch());

        $accounts = $this->readAccounts();
        $this->assertSame('+6281113900527', $accounts['9']['number']);
        $this->assertSame('110', $accounts['9']['account_id']);
        $this->assertCount(2, $accounts['9']['channels']);
        $this->assertSame('110', (string) $accounts['9']['channels'][0]['account_id']);
        $this->assertSame('107', (string) $accounts['9']['channels'][1]['account_id']);
        $this->assertSame('dkt@trans-cosmos.co.id', $accounts['9']['username']);

        $loaded = CxpWhatsAppAccounts::fromConfig()->forWorkspace('9');
        $this->assertNotNull($loaded);
        $this->assertSame('+6281113900527', $loaded['number']);
        $this->assertSame('110', $loaded['account_id']);
        $this->assertSame('dkt@trans-cosmos.co.id', $loaded['username']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $accounts
     */
    private function writeAccounts(array $accounts): void
    {
        file_put_contents($this->file, json_encode($accounts, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readAccounts(): array
    {
        return json_decode((string) file_get_contents($this->file), true);
    }
}
