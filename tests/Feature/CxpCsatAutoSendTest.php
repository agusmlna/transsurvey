<?php

namespace Tests\Feature;

use App\Jobs\SendSurveyEmail;
use App\Models\{Client, EmailDelivery, Invitation, Survey, User, Workspace};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Artisan, Http, Queue};
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * cxp:csat-send harus aman dijalankan berkala: hanya tiket Close setelah
 * tanggal batas, tanpa email tidak dikirimi, dan tanpa --send tidak menulis
 * apa pun.
 */
class CxpCsatAutoSendTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Survey $template;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cxp.api.base_url' => 'https://cxp.example.test',
            'cxp.api.email' => 'api-user@example.test',
            'cxp.api.password' => 'password-rahasia',
            'cxp.api.timeout' => 5,
            'cxp.api.auth_header' => 'Authorization',
            'cxp.api.auth_prefix' => 'Bearer',
            'cxp.api.ticket_path' => '/api/v2/tickets',
            'cxp.api.detail_path' => '/api/v2/tickets',
            'cxp.csat.enabled' => false,
            'cxp.csat.workspace' => 'brand-a',
            'cxp.csat.since' => '2026-11-11',
            'cxp.csat.max_pages' => 0,
            'survey.email_enabled' => true,
            'mail.default' => 'smtp',
        ]);

        Queue::fake();

        $this->workspace = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);

        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'password' => 'OnlyTesting123!',
            'role' => 'superadmin',
        ]);
        $this->admin = $admin;

        $this->template = new Survey([
            'title' => 'Survei Kepuasan Layanan',
            'description' => 'Bantu kami meningkatkan layanan.',
            'type' => 'csat',
            'status' => 'active',
            'starts_at' => today(),
            'ends_at' => null,
            'is_template' => true,
            'version' => 1,
            'created_by' => $admin->id,
        ]);
        $this->template->workspace_id = $this->workspace->id;
        $this->template->save();

        $this->workspace->default_csat_survey_id = $this->template->id;
        $this->workspace->save();
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $options = []): array
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-send', $options, $output);

        return [$exitCode, $output->fetch()];
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $pages
     */
    private function fakeTickets(array $pages): void
    {
        $lastPage = max(1, count($pages));

        Http::fake(function (Request $request) use ($pages, $lastPage) {
            if (str_contains($request->url(), '/api/v1/login-user')) {
                return Http::response([
                    'statusCode' => 200,
                    'userInfo' => [
                        'name' => 'API User',
                        'email' => 'api-user@example.test',
                        'token' => 'token-tidak-boleh-tampil',
                        'expired_date' => '2027-02-11 10:00:00',
                    ],
                ], 200);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $rows = array_values($pages[$page - 1] ?? []);

            return Http::response([
                'statusCode' => 200,
                'message' => 'ok',
                'data' => $rows,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => 20,
                    'total' => array_sum(array_map('count', $pages)),
                ],
            ], 200);
        });
    }

    /**
     * Respons /api/v1/dataTicketcxp: envelope "status/code/data" dengan
     * pagination di kunci "pagination", bukan "meta" seperti v2.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $pages
     */
    private function fakeV1Tickets(array $pages): void
    {
        $lastPage = max(1, count($pages));

        Http::fake(function (Request $request) use ($pages, $lastPage) {
            if (str_contains($request->url(), '/api/v1/login-user')) {
                return Http::response([
                    'statusCode' => 200,
                    'userInfo' => [
                        'name' => 'API User',
                        'email' => 'api-user@example.test',
                        'token' => 'token-tidak-boleh-tampil',
                        'expired_date' => '2027-02-11 10:00:00',
                    ],
                ], 200);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);

            return Http::response([
                'status' => 'success',
                'code' => 200,
                'message' => 'Ticket data has been successfully retrieved.',
                'data' => array_values($pages[$page - 1] ?? []),
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => 20,
                    'total' => array_sum(array_map('count', $pages)),
                    'last_page' => $lastPage,
                    'next_page_url' => $page < $lastPage ? 'next' : null,
                    'prev_page_url' => $page > 1 ? 'prev' : null,
                ],
            ], 200);
        });
    }

    /**
     * Satu baris tiket format v1: email/status/wa ada di root.
     *
     * @return array<string, mixed>
     */
    private function ticketV1(string $id, string $project = 'brand-a', array $overrides = []): array
    {
        return array_merge([
            'id_ticket' => $id,
            'id_project' => $project,
            'project_name' => 'Brand A',
            'name_customer' => 'Sari',
            'email' => 'sari@example.test',
            'wa' => null,
            'phone_no' => null,
            'mobile_phone' => null,
            'status_ticket' => 'Close',
            'source' => 'Email',
            'start_date' => '2026-11-11 08:00:00',
            'update_date' => '2026-11-11 10:00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    private function ticket(string $id, string $email = 'sari@example.test', array $customer = []): array
    {
        return [
            'id_ticket' => $id,
            'status_name' => 'Close',
            'source' => 'Email',
            'start_date' => '2026-11-11 08:00:00',
            'update_date' => '2026-11-11 10:00:00',
            'customer' => array_merge([
                'id' => 10,
                'name_customer' => 'Sari',
                'email' => $email,
                'wa' => null,
                'phone_no' => null,
                'mobile_phone' => null,
            ], $customer),
        ];
    }

    private function existingInvitation(string $ticketId, array $attributes = []): Invitation
    {
        $client = new Client([
            'name' => 'Sari',
            'contact' => 'Sari',
            'email' => 'sari@example.test',
            'project' => 'Brand A',
            'active' => true,
        ]);
        $client->workspace_id = $this->workspace->id;
        $client->save();

        $token = Str::random(64);

        return Invitation::create(array_merge([
            'survey_id' => $this->template->id,
            'client_id' => $client->id,
            'ticket_id' => $ticketId,
            'ticket_status' => 'Close',
            'channel' => 'Email',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'recipient_name' => 'Sari',
            'recipient_email' => 'sari@example.test',
            'is_demo' => false,
        ], $attributes));
    }

    /**
     * Workspace kedua lengkap dengan template CSAT default aktif.
     *
     * @return array{0: Workspace, 1: Survey}
     */
    private function workspaceWithTemplate(string $code, string $name): array
    {
        $workspace = Workspace::create(['name' => $name, 'code' => $code]);

        $template = new Survey([
            'title' => 'CSAT '.$name,
            'description' => 'Template CSAT '.$name,
            'type' => 'csat',
            'status' => 'active',
            'starts_at' => today(),
            'ends_at' => null,
            'is_template' => true,
            'version' => 1,
            'created_by' => $this->admin->id,
        ]);
        $template->workspace_id = $workspace->id;
        $template->save();

        $workspace->default_csat_survey_id = $template->id;
        $workspace->save();

        return [$workspace, $template];
    }

    public function test_dry_run_reports_eligible_tickets_without_writing_anything(): void
    {
        $this->existingInvitation('000004', ['sent_at' => now()]);

        $this->fakeTickets([[
            $this->ticket('000001'),
            $this->ticket('000002', ''),
            $this->ticket('000003', 'bukan-email'),
            $this->ticket('000004'),
            array_replace($this->ticket('000005'), ['update_date' => '2026-11-01 10:00:00']),
            array_replace($this->ticket('000006'), ['status_name' => 'Open']),
        ]]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SIMULASI', $output);
        $this->assertStringContainsString('Akan diantrekan', $output);
        $this->assertStringContainsString('000001', $output);
        $this->assertStringNotContainsString('000002', $output);
        $this->assertStringNotContainsString('000003', $output);
        $this->assertStringNotContainsString('000004', $output);
        $this->assertStringNotContainsString('000005', $output);
        $this->assertStringNotContainsString('000006', $output);

        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(1, Client::withoutGlobalScopes()->count());
        $this->assertSame(0, EmailDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_send_creates_invitation_and_saves_normalized_whatsapp(): void
    {
        $this->fakeTickets([[
            $this->ticket('000001', 'sari@example.test', ['wa' => '0812-3456-7890']),
        ]]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Email diantrekan', $output);

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', '000001')->sole();
        $this->assertSame($this->workspace->id, $invitation->workspace_id);
        $this->assertSame('sari@example.test', $invitation->recipient_email);
        $this->assertSame('Close', $invitation->ticket_status);
        $this->assertSame('Email', $invitation->channel);
        $this->assertNull($invitation->sent_at);

        $client = Client::withoutGlobalScopes()->where('email', 'sari@example.test')->sole();
        $this->assertSame('6281234567890', $client->wa);

        $this->assertSame(1, EmailDelivery::where('invitation_id', $invitation->id)->count());
        Queue::assertPushed(SendSurveyEmail::class);
    }

    public function test_tickets_without_email_or_older_than_the_limit_are_skipped(): void
    {
        $this->fakeTickets([[
            $this->ticket('000001'),
            $this->ticket('000002', ''),
            $this->ticket('000003', 'bukan-email'),
            array_replace($this->ticket('000004'), ['update_date' => '2026-11-01 09:00:00']),
            array_replace($this->ticket('000005'), ['status_name' => 'Open']),
        ]]);

        [$exitCode] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['000001'], Invitation::withoutGlobalScopes()->pluck('ticket_id')->all());
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_ticket_that_already_has_csat_is_not_sent_again(): void
    {
        $this->existingInvitation('000001', ['sent_at' => now()]);
        $this->fakeTickets([[$this->ticket('000001')]]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Sudah pernah dibuatkan CSAT', $output);
        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(0, EmailDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_all_pages_are_read_until_last_page(): void
    {
        $this->fakeTickets([
            [$this->ticket('000001')],
            [$this->ticket('000002'), $this->ticket('000003', '')],
            [$this->ticket('000004')],
        ]);

        [$exitCode] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertEqualsCanonicalizing(
            ['000001', '000002', '000004'],
            Invitation::withoutGlobalScopes()->pluck('ticket_id')->all(),
        );
    }

    public function test_v1_pagination_and_project_fields_are_used(): void
    {
        [$brandB] = $this->workspaceWithTemplate('brand-b', 'Brand B');

        $this->fakeV1Tickets([
            [$this->ticketV1('000001')],
            [$this->ticketV1('000002', 'brand-b')],
            [$this->ticketV1('000003', 'brand-tidak-ada', ['project_name' => 'Brand Tidak Ada'])],
        ]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);

        // Halaman 2 dan 3 hanya terbaca kalau pagination.last_page dipakai.
        $this->assertEqualsCanonicalizing(
            ['000001', '000002'],
            Invitation::withoutGlobalScopes()->pluck('ticket_id')->all(),
        );

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', '000002')->sole();
        $this->assertSame($brandB->id, $invitation->workspace_id);
        $this->assertStringContainsString('Project tanpa workspace', $output);
    }

    public function test_max_pages_and_limit_stop_processing_early(): void
    {
        $this->fakeTickets([
            [$this->ticket('000001'), $this->ticket('000002')],
            [$this->ticket('000003')],
        ]);

        [$exitCode] = $this->runCommand(['--send' => true, '--max-pages' => 1]);

        $this->assertSame(0, $exitCode);
        $this->assertEqualsCanonicalizing(
            ['000001', '000002'],
            Invitation::withoutGlobalScopes()->pluck('ticket_id')->all(),
        );

        EmailDelivery::query()->delete();
        Invitation::withoutGlobalScopes()->delete();

        [$exitCode] = $this->runCommand(['--send' => true, '--limit' => 1]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
    }

    public function test_invalid_whatsapp_is_not_saved_but_email_still_queued(): void
    {
        $this->fakeTickets([[
            $this->ticket('000001', 'sari@example.test', ['wa' => 'bukan-nomor']),
        ]]);

        [$exitCode] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertNull(Client::withoutGlobalScopes()->sole()->wa);
        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
    }

    public function test_ticket_option_is_forwarded_to_the_api(): void
    {
        $this->fakeTickets([[$this->ticket('000001')]]);

        [$exitCode] = $this->runCommand(['--send' => true, '--ticket' => '000001']);

        $this->assertSame(0, $exitCode);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v2/tickets')
            && str_contains($request->url(), 'status=Close')
            && str_contains($request->url(), 'id_ticket=000001'));
    }

    public function test_missing_workspace_or_since_aborts_before_login(): void
    {
        config(['cxp.csat.workspace' => '']);
        Http::fake();

        [$exitCode, $output] = $this->runCommand();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('CXP_CSAT_WORKSPACE', $output);
        Http::assertNothingSent();

        config(['cxp.csat.workspace' => 'brand-a', 'cxp.csat.since' => '']);

        [$exitCode, $output] = $this->runCommand();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('CXP_CSAT_SINCE', $output);
        Http::assertNothingSent();
    }

    public function test_workspace_without_default_template_aborts(): void
    {
        $this->workspace->default_csat_survey_id = null;
        $this->workspace->save();
        Http::fake();

        [$exitCode, $output] = $this->runCommand();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('belum punya template CSAT aktif', $output);
        Http::assertNothingSent();
    }

    public function test_unknown_workspace_code_aborts(): void
    {
        config(['cxp.csat.workspace' => 'tidak-ada']);
        Http::fake();

        [$exitCode, $output] = $this->runCommand();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('tidak ditemukan', $output);
        Http::assertNothingSent();
    }

    public function test_ticket_with_known_project_uses_that_workspace_and_template(): void
    {
        [$brandB, $templateB] = $this->workspaceWithTemplate('brand-b', 'Brand B');

        $this->fakeTickets([[
            array_replace($this->ticket('000001'), ['id_project' => 'brand-b']),
        ]]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Email diantrekan', $output);

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', '000001')->sole();
        $this->assertSame($brandB->id, $invitation->workspace_id);
        $this->assertSame($templateB->id, $invitation->survey_id);

        $client = Client::withoutGlobalScopes()->where('email', 'sari@example.test')->sole();
        $this->assertSame($brandB->id, $client->workspace_id);
    }

    public function test_project_name_is_used_when_id_project_is_numeric(): void
    {
        [$brandB] = $this->workspaceWithTemplate('brand-b', 'Brand B');

        $this->fakeTickets([[
            array_replace($this->ticket('000001'), [
                'id_project' => '6',
                'project_name' => 'Brand B',
            ]),
        ]]);

        [$exitCode] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', '000001')->sole();
        $this->assertSame($brandB->id, $invitation->workspace_id);
    }

    public function test_ticket_with_unknown_or_legacy_project_is_skipped(): void
    {
        Workspace::create(['name' => 'Legacy', 'code' => 'legacy-x', 'is_legacy' => true]);

        $this->fakeTickets([[
            array_replace($this->ticket('000001'), ['id_project' => 'brand-tidak-ada']),
            array_replace($this->ticket('000002'), ['id_project' => 'legacy-x']),
        ]]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Project tanpa workspace', $output);
        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_ticket_without_project_uses_default_workspace_and_v1_fields(): void
    {
        // Bentuk kontrak v1: data customer dan status ada di root, tanpa objek customer.
        $this->fakeTickets([[
            [
                'id_ticket' => '000010',
                'status_ticket' => 'Close',
                'name_customer' => 'Sari',
                'email' => 'sari@example.test',
                'wa' => '0812-3456-7890',
                'update_date' => '2026-11-11 10:00:00',
            ],
        ]]);

        [$exitCode] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', '000010')->sole();
        $this->assertSame($this->workspace->id, $invitation->workspace_id);
        $this->assertSame('sari@example.test', $invitation->recipient_email);
        $this->assertSame('Close', $invitation->ticket_status);

        $client = Client::withoutGlobalScopes()->sole();
        $this->assertSame('6281234567890', $client->wa);
    }

    public function test_same_ticket_id_in_another_project_is_not_a_duplicate(): void
    {
        $this->existingInvitation('000001', ['sent_at' => now()]);
        [$brandB] = $this->workspaceWithTemplate('brand-b', 'Brand B');

        $this->fakeTickets([[
            $this->ticket('000001'),
            array_replace($this->ticket('000001'), ['id_project' => 'brand-b']),
        ]]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Sudah pernah dibuatkan CSAT', $output);
        $this->assertSame(2, Invitation::withoutGlobalScopes()->where('ticket_id', '000001')->count());
        $this->assertSame(1, Invitation::withoutGlobalScopes()
            ->where('workspace_id', $brandB->id)
            ->where('ticket_id', '000001')
            ->count());
    }
}
