<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Command cxp:inspect harus aman dipakai di staging: read-only, tidak
 * menampilkan token/password, dan menyamarkan data pribadi secara default.
 */
class CxpApiInspectTest extends TestCase
{
    private const BASE_URL = 'https://crm.example.test';

    private const TOKEN = 'token-rahasia-yang-tidak-boleh-tampil-0123456789abcdef';

    private const PASSWORD = 'password-rahasia-transcxp';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cxp.api.base_url' => self::BASE_URL,
            'cxp.api.email' => 'api-user@example.test',
            'cxp.api.password' => self::PASSWORD,
            'cxp.api.timeout' => 5,
            'cxp.api.auth_header' => 'Authorization',
            'cxp.api.auth_prefix' => 'Bearer',
        ]);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runInspect(array $options = []): array
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:inspect', $options, $output);

        return [$exitCode, $output->fetch()];
    }

    private function fakeApi(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/login-user' => Http::response([
                'statusCode' => 201,
                'message' => 'Generate token successfully!',
                'userInfo' => [
                    'name' => 'User Access 1',
                    'email' => 'api-user@example.test',
                    'token' => self::TOKEN,
                    'expired_date' => '2026-12-31 23:59:59',
                ],
            ], 201),
            self::BASE_URL.'/api/v1/dataTicketcxp*' => Http::response([
                'status' => 'success',
                'code' => 200,
                'message' => 'Ticket data has been successfully retrieved.',
                'data' => [
                    [
                        'id_ticket' => '000152',
                        'id_project' => '6',
                        'project_name' => 'DIPOOPL',
                        'email' => 'dewi@example.test',
                        'wa' => '087700000000',
                        'phone_no' => '087700000000',
                        'mobile_phone' => null,
                        'status_ticket' => 'Open',
                        'source' => 'Call',
                        'subject' => 'Testing add new field',
                        'start_date' => '2026-09-14 15:33:16',
                        'end_date' => null,
                    ],
                    [
                        'id_ticket' => '000016',
                        'id_project' => '10',
                        'project_name' => 'Trakindo',
                        'email' => null,
                        'wa' => ' ',
                        'phone_no' => '6282115159111',
                        'mobile_phone' => null,
                        'status_ticket' => 'Solved',
                        'source' => 'Email',
                        'subject' => 'OPL 1',
                        'start_date' => '2026-09-04 09:30:55',
                        'end_date' => null,
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 10,
                    'total' => 2,
                    'last_page' => 1,
                    'next_page_url' => null,
                    'prev_page_url' => null,
                ],
            ], 200),
            self::BASE_URL.'/api/v1/detailChoose*' => Http::response([
                'status' => 'success',
                'code' => 200,
                'message' => 'Ticket details fetched successfully',
                'data' => [
                    [
                        'id_ticket' => '000152',
                        'id_project' => '6',
                        'project_name' => 'DIPOOPL',
                        'email' => 'dewi@example.test',
                        'wa' => '087700000000',
                        'phone_no' => null,
                        'mobile_phone' => null,
                        'status_ticket' => 'Open',
                        'source' => 'Call',
                        'subject' => 'Testing add new field',
                        'end_date' => null,
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 10,
                    'total' => 1,
                    'last_page' => 1,
                ],
            ], 200),
        ]);
    }

    public function test_inspect_logs_in_lists_tickets_and_masks_pii(): void
    {
        $this->fakeApi();

        [$exitCode, $output] = $this->runInspect(['--per-page' => 5]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Login berhasil.', $output);
        $this->assertStringContainsString('dataTicketcxp', $output);
        $this->assertStringContainsString('d***@example.test', $output);
        $this->assertStringContainsString('6287700000000', $output);
        $this->assertStringContainsString('Read-only', $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
        $this->assertStringNotContainsString('dewi@example.test', $output);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/api/v1/dataTicketcxp')) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && str_contains($request->url(), 'per_page=5')
                && str_contains($request->url(), 'page=1');
        });
    }

    public function test_inspect_can_fetch_ticket_detail(): void
    {
        $this->fakeApi();

        [$exitCode, $output] = $this->runInspect([
            '--ticket' => '000152',
            '--detail' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('detailChoose', $output);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v1/detailChoose')
            && str_contains($request->url(), 'id_ticket=000152'));
    }

    public function test_inspect_show_pii_prints_real_email(): void
    {
        $this->fakeApi();

        [$exitCode, $output] = $this->runInspect(['--show-pii' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('dewi@example.test', $output);
        $this->assertStringNotContainsString('d***@example.test', $output);
    }

    public function test_inspect_uses_auth_preset_from_option(): void
    {
        $this->fakeApi();

        [$exitCode, $output] = $this->runInspect(['--auth' => 'x-api-key']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('X-API-KEY', $output);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v1/dataTicketcxp')
            && $request->hasHeader('X-API-KEY', self::TOKEN));
    }

    public function test_inspect_rejects_unknown_auth_preset(): void
    {
        Http::fake();

        [$exitCode, $output] = $this->runInspect(['--auth' => 'basic']);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Skema autentikasi tidak dikenal', $output);

        Http::assertNothingSent();
    }

    public function test_inspect_rejects_invalid_per_page(): void
    {
        Http::fake();

        [$exitCode, $output] = $this->runInspect(['--per-page' => 99]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('--per-page harus 1-50', $output);

        Http::assertNothingSent();
    }

    public function test_inspect_reports_missing_configuration_without_calling_api(): void
    {
        Http::fake();

        config([
            'cxp.api.base_url' => '',
            'cxp.api.email' => '',
            'cxp.api.password' => '',
        ]);

        [$exitCode, $output] = $this->runInspect();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('CXP_API_BASE_URL', $output);
        $this->assertStringContainsString('CXP_API_EMAIL', $output);
        $this->assertStringContainsString('CXP_API_PASSWORD', $output);
        $this->assertStringContainsString('optimize:clear', $output);

        Http::assertNothingSent();
    }

    public function test_inspect_reports_login_failure_without_leaking_credentials(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/login-user' => Http::response([
                'statusCode' => 401,
                'message' => 'Incorrect password!',
            ], 401),
        ]);

        [$exitCode, $output] = $this->runInspect();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Incorrect password!', $output);
        $this->assertStringContainsString('--auth=token', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v1/login-user')
            && $request->data() === [
                'email' => 'api-user@example.test',
                'password' => self::PASSWORD,
            ]);
    }

    public function test_inspect_reports_ticket_api_rejection(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/login-user' => Http::response([
                'statusCode' => 200,
                'userInfo' => [
                    'name' => 'User Access 1',
                    'email' => 'api-user@example.test',
                    'token' => self::TOKEN,
                    'expired_date' => '2026-12-31 23:59:59',
                ],
            ], 200),
            self::BASE_URL.'/api/v1/dataTicketcxp*' => Http::response([
                'status' => 'error',
                'code' => 500,
                'message' => 'Server Error',
            ], 500),
        ]);

        [$exitCode, $output] = $this->runInspect();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Server Error', $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);
    }
}
