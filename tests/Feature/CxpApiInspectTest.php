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
            'cxp.api.ticket_path' => '/api/v2/tickets',
            'cxp.api.detail_path' => '/api/v2/ticket-detail',
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
            self::BASE_URL.'/api/v2/tickets*' => Http::response([
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
            self::BASE_URL.'/api/v2/ticket-detail*' => Http::response([
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
        $this->assertStringContainsString('Tiket    : /api/v2/tickets', $output);
        $this->assertStringContainsString('Daftar tiket (/api/v2/tickets)', $output);
        $this->assertStringContainsString('d***@example.test', $output);
        $this->assertStringContainsString('6287700000000', $output);
        $this->assertStringContainsString('Read-only', $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
        $this->assertStringNotContainsString('dewi@example.test', $output);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/api/v2/tickets')) {
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
        $this->assertStringContainsString('Detail tiket (/api/v2/ticket-detail)', $output);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v2/ticket-detail')
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

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v2/tickets')
            && $request->hasHeader('X-API-KEY', self::TOKEN));
    }

    public function test_inspect_respects_custom_ticket_path(): void
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
            self::BASE_URL.'/api/v9/tiket*' => Http::response([
                'status' => 'success',
                'code' => 200,
                'message' => 'ok',
                'data' => [],
            ], 200),
        ]);

        config(['cxp.api.ticket_path' => 'api/v9/tiket/']);

        [$exitCode, $output] = $this->runInspect();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Tiket    : /api/v9/tiket', $output);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v9/tiket'));
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

    public function test_inspect_sends_username_field_when_configured(): void
    {
        $this->fakeApi();

        config([
            'cxp.api.email' => 'operator-cxp',
            'cxp.api.login_field' => 'username',
        ]);

        [$exitCode, $output] = $this->runInspect();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Login    : username', $output);
        $this->assertStringNotContainsString('tidak berbentuk email', $output);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v1/login-user')
            && $request->data() === [
                'username' => 'operator-cxp',
                'password' => self::PASSWORD,
            ]);
    }

    public function test_inspect_can_override_login_field_from_option(): void
    {
        $this->fakeApi();

        config(['cxp.api.email' => 'operator-cxp']);

        [$exitCode, $output] = $this->runInspect(['--login-field' => 'username']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Login    : username', $output);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v1/login-user')
            && $request->data() === [
                'username' => 'operator-cxp',
                'password' => self::PASSWORD,
            ]);
    }

    public function test_inspect_warns_when_email_field_receives_non_email(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/login-user' => Http::response([
                'statusCode' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'email' => ['The email must be a valid email address.'],
                ],
            ], 422),
        ]);

        config(['cxp.api.email' => 'operator-cxp']);

        [$exitCode, $output] = $this->runInspect();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('tidak berbentuk email', $output);
        $this->assertStringContainsString('--login-field=username', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
    }

    public function test_inspect_explains_email_required_when_username_is_sent(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/login-user' => Http::response([
                'statusCode' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'email' => ['The email field is required.'],
                ],
            ], 422),
        ]);

        config([
            'cxp.api.email' => 'operator-cxp',
            'cxp.api.login_field' => 'username',
        ]);

        [$exitCode, $output] = $this->runInspect();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('hanya menerima field email', $output);
        $this->assertStringContainsString('--login-field=email', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
    }

    public function test_inspect_rejects_unknown_login_field(): void
    {
        Http::fake();

        [$exitCode, $output] = $this->runInspect(['--login-field' => 'user']);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Field login tidak dikenal', $output);

        Http::assertNothingSent();
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
            self::BASE_URL.'/api/v2/tickets*' => Http::response([
                'status' => 'error',
                'code' => 500,
                'message' => 'Server Error',
            ], 500),
        ]);

        [$exitCode, $output] = $this->runInspect();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Server Error', $output);
        $this->assertStringContainsString('Status HTTP : 500', $output);
        $this->assertStringContainsString('Field respons: status, code, message', $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);
    }

    public function test_inspect_explains_missing_ticket_endpoint(): void
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
            self::BASE_URL.'/api/v2/tickets*' => Http::response(
                '<html><body>Not Found</body></html>',
                404,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        [$exitCode, $output] = $this->runInspect();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Status HTTP : 404', $output);
        $this->assertStringContainsString('Content-Type: text/html', $output);
        $this->assertStringContainsString('Endpoint tidak ditemukan (/api/v2/tickets)', $output);
        $this->assertStringContainsString('CXP_API_TICKET_PATH', $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);
    }

    public function test_inspect_keys_mode_prints_field_names_without_values(): void
    {
        $this->fakeApi();

        [$exitCode, $output] = $this->runInspect(['--keys' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Field envelope', $output);
        $this->assertStringContainsString('Field tiket', $output);
        $this->assertStringContainsString('id_ticket', $output);
        $this->assertStringContainsString('status_ticket', $output);
        $this->assertStringNotContainsString('dewi@example.test', $output);
        $this->assertStringNotContainsString('Testing add new field', $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);
    }

    public function test_inspect_sends_server_side_filters(): void
    {
        $this->fakeApi();

        [$exitCode] = $this->runInspect([
            '--status' => 'Close',
            '--from' => '2026-11-01',
            '--to' => '2026-11-10',
            '--per-page' => 5,
        ]);

        $this->assertSame(0, $exitCode);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v2/tickets')
            && str_contains($request->url(), 'status=Close')
            && str_contains($request->url(), 'start_date=2026-11-01')
            && str_contains($request->url(), 'end_date=2026-11-10'));
    }

    public function test_inspect_rejects_invalid_date_filter(): void
    {
        Http::fake();

        [$exitCode, $output] = $this->runInspect(['--from' => '01-11-2026']);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('--from harus berformat Y-m-d', $output);

        Http::assertNothingSent();
    }

    public function test_inspect_probe_reports_filter_counts_without_ticket_values(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/api/v1/login-user')) {
                return Http::response([
                    'statusCode' => 200,
                    'userInfo' => [
                        'name' => 'User Access 1',
                        'email' => 'api-user@example.test',
                        'token' => self::TOKEN,
                        'expired_date' => '2026-12-31 23:59:59',
                    ],
                ], 200);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $total = ($query['status'] ?? null) === 'Close' ? 900 : 1042;

            return Http::response([
                'statusCode' => 200,
                'message' => 'ok',
                'data' => [
                    ['id_ticket' => '001074', 'customer' => ['email' => 'budi@example.test']],
                ],
                'meta' => ['current_page' => 1, 'total' => $total, 'per_page' => 5],
            ], 200);
        });

        [$exitCode, $output] = $this->runInspect(['--probe' => true, '--per-page' => 5]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Probe filter', $output);
        $this->assertStringContainsString('status=Close', $output);
        $this->assertStringContainsString('900', $output);
        $this->assertStringContainsString('1042', $output);
        $this->assertStringNotContainsString('budi@example.test', $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);
    }

    public function test_inspect_maps_v2_nested_customer_fields(): void
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
            self::BASE_URL.'/api/v2/tickets*' => Http::response([
                'statusCode' => 200,
                'message' => 'ok',
                'data' => [
                    [
                        'id' => 74,
                        'id_ticket' => '001074',
                        'subject' => 'test',
                        'source' => 'Email',
                        'status_name' => 'Solved',
                        'start_date' => '2026-11-09 15:00:00',
                        'end_date' => '2026-11-09 17:00:28',
                        'customer' => [
                            'name' => 'Budi',
                            'email' => 'budi@example.test',
                            'wa' => '081234567890',
                        ],
                    ],
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 2,
                    'total' => 21,
                    'per_page' => 20,
                ],
            ], 200),
        ]);

        [$exitCode, $output] = $this->runInspect(['--per-page' => 5]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Halaman 1 dari 2 | total 21 | 20 per halaman', $output);
        $this->assertStringContainsString('Solved', $output);
        $this->assertStringContainsString('b***@example.test', $output);
        $this->assertStringContainsString('6281234567890', $output);
        $this->assertStringContainsString('2026-11-09 17:00:28', $output);
        $this->assertStringNotContainsString('budi@example.test', $output);
        $this->assertStringNotContainsString('081234567890', $output);
    }

    public function test_inspect_keys_mode_shows_nested_fields(): void
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
            self::BASE_URL.'/api/v2/tickets*' => Http::response([
                'statusCode' => 200,
                'message' => 'ok',
                'data' => [
                    [
                        'id_ticket' => '001074',
                        'status_name' => 'Solved',
                        'customer' => [
                            'name' => 'Budi',
                            'email' => 'budi@example.test',
                            'wa' => '081234567890',
                        ],
                    ],
                ],
                'meta' => ['current_page' => 1, 'total' => 1],
            ], 200),
        ]);

        [$exitCode, $output] = $this->runInspect(['--keys' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('customer (objek)', $output);
        $this->assertStringContainsString('customer.email (string)', $output);
        $this->assertStringContainsString('customer.wa (string)', $output);
        $this->assertStringContainsString('meta.current_page (int)', $output);
        $this->assertStringNotContainsString('budi@example.test', $output);
        $this->assertStringNotContainsString('081234567890', $output);
    }

    public function test_inspect_json_masks_unknown_email_and_phone_fields(): void
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
            self::BASE_URL.'/api/v2/tickets*' => Http::response([
                'data' => [
                    [
                        'id_ticket' => '001074',
                        'customer_email' => 'rahasia@example.test',
                        'customer_phone' => '081234567890',
                        'subject' => 'test',
                    ],
                ],
                'meta' => ['total' => 1],
            ], 200),
        ]);

        [$exitCode, $output] = $this->runInspect(['--json' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('r***@example.test', $output);
        $this->assertStringNotContainsString('rahasia@example.test', $output);
        $this->assertStringNotContainsString('081234567890', $output);
    }

    public function test_inspect_explains_ticket_endpoint_auth_rejection(): void
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
            self::BASE_URL.'/api/v2/tickets*' => Http::response('', 401),
        ]);

        [$exitCode, $output] = $this->runInspect();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Status HTTP : 401', $output);
        $this->assertStringContainsString('--auth=token', $output);
        $this->assertStringNotContainsString(self::TOKEN, $output);
    }
}
