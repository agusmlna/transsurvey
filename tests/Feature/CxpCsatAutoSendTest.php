<?php

namespace Tests\Feature;

use App\Jobs\SendSurveyEmail;
use App\Models\{Client, CxpTicketSeen, EmailDelivery, Invitation, Survey, User, Workspace};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Artisan, Http, Queue};
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * cxp:csat-send membaca /api/v1/dataTicketByStatus per project, lalu memakai
 * snapshot cxp_ticket_seen supaya hanya tiket yang BARU masuk Close yang
 * dikirimi CSAT.
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
            'cxp.api.status_path' => '/api/v1/dataTicketByStatus',
            'cxp.csat.enabled' => false,
            'cxp.csat.workspace' => '',
            'cxp.csat.per_page' => 100,
            'cxp.csat.max_pages' => 0,
            'survey.email_enabled' => true,
            'mail.default' => 'smtp',
        ]);

        Queue::fake();

        $this->workspace = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);

        $this->admin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'password' => 'OnlyTesting123!',
            'role' => 'superadmin',
        ]);

        $this->template = $this->createTemplate($this->workspace, 'Survei Kepuasan Layanan');
        $this->workspace->default_csat_survey_id = $this->template->id;
        $this->workspace->save();
    }

    private function createTemplate(Workspace $workspace, string $title, string $status = 'active'): Survey
    {
        $template = new Survey([
            'title' => $title,
            'description' => 'Bantu kami meningkatkan layanan.',
            'type' => 'csat',
            'status' => $status,
            'starts_at' => today(),
            'ends_at' => null,
            'is_template' => true,
            'version' => 1,
            'created_by' => $this->admin->id,
        ]);
        $template->workspace_id = $workspace->id;
        $template->save();

        return $template;
    }

    /**
     * @return array{0: Workspace, 1: Survey}
     */
    private function workspaceWithTemplate(string $code, string $name): array
    {
        $workspace = Workspace::create(['name' => $name, 'code' => $code]);
        $template = $this->createTemplate($workspace, 'CSAT '.$name);

        $workspace->default_csat_survey_id = $template->id;
        $workspace->save();

        return [$workspace, $template];
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
     * Fake endpoint /api/v1/dataTicketByStatus.
     *
     * @param  array<string, array<int, array<int, array<string, mixed>>>>  $projects  project code => halaman => baris
     * @param  array<int, string>  $failingProjects
     */
    private function fakeStatusTickets(array $projects, array $failingProjects = []): void
    {
        Http::fake(function (Request $request) use ($projects, $failingProjects) {
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
            $project = (string) ($query['project'] ?? '');

            if (in_array($project, $failingProjects, true)) {
                return Http::response(['message' => 'Server error'], 500);
            }

            $status = (string) ($query['status'] ?? '');
            $page = max(1, (int) ($query['page'] ?? 1));
            $pages = $projects[$project] ?? [];
            $lastPage = max(1, count($pages));

            return Http::response([
                'status' => 'success',
                'code' => 200,
                'message' => 'ok',
                'data' => $status === 'Close' ? array_values($pages[$page - 1] ?? []) : [],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => 100,
                    'total' => array_sum(array_map('count', $pages)),
                    'last_page' => $lastPage,
                ],
            ], 200);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketStatus(string $id, string $email = 'sari@example.test', array $overrides = []): array
    {
        return array_merge([
            'id_ticket' => $id,
            'id_project' => 'brand-a',
            'project_name' => 'Brand A',
            'name_customer' => 'Sari',
            'email' => $email,
            'wa' => null,
            'phone_no' => null,
            'mobile_phone' => null,
            'status_ticket' => 'Close',
            'source' => 'Email',
        ], $overrides);
    }

    /**
     * @param  array<int, string>  $ticketIds
     */
    private function seedSnapshot(string $projectCode, array $ticketIds = ['seed']): void
    {
        foreach ($ticketIds as $ticketId) {
            CxpTicketSeen::create([
                'project_code' => $projectCode,
                'ticket_id' => $ticketId,
                'first_seen_at' => now(),
            ]);
        }
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

    public function test_dry_run_on_a_new_project_only_reports_baseline_and_writes_nothing(): void
    {
        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001'), $this->ticketStatus('000002')]],
        ]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SIMULASI', $output);
        $this->assertStringContainsString('Baseline', $output);
        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
        $this->assertSame(0, CxpTicketSeen::count());
        Queue::assertNothingPushed();
    }

    public function test_first_real_run_only_records_the_baseline(): void
    {
        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001'), $this->ticketStatus('000002')]],
        ]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Baseline', $output);
        $this->assertSame(2, CxpTicketSeen::count());
        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(0, EmailDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_second_run_sends_only_tickets_that_are_newly_closed(): void
    {
        $this->seedSnapshot('brand-a', ['000001']);

        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001'), $this->ticketStatus('000002')]],
        ]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Email diantrekan', $output);

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', '000002')->sole();
        $this->assertSame($this->workspace->id, $invitation->workspace_id);
        $this->assertSame($this->template->id, $invitation->survey_id);
        $this->assertSame('sari@example.test', $invitation->recipient_email);
        $this->assertSame('Close', $invitation->ticket_status);
        $this->assertNull(Invitation::withoutGlobalScopes()->where('ticket_id', '000001')->first());

        $this->assertSame(2, CxpTicketSeen::where('project_code', 'brand-a')->count());
        Queue::assertPushed(SendSurveyEmail::class);
    }

    public function test_tickets_without_email_or_with_invalid_email_are_skipped_and_remembered(): void
    {
        $this->seedSnapshot('brand-a');

        $this->fakeStatusTickets([
            'brand-a' => [[
                $this->ticketStatus('000001'),
                $this->ticketStatus('000002', ''),
                $this->ticketStatus('000003', 'bukan-email'),
            ]],
        ]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Tanpa email', $output);
        $this->assertStringContainsString('Email tidak valid', $output);
        $this->assertSame(['000001'], Invitation::withoutGlobalScopes()->pluck('ticket_id')->all());
        $this->assertSame(4, CxpTicketSeen::count());
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_ticket_that_already_has_csat_is_not_sent_again(): void
    {
        $this->seedSnapshot('brand-a');
        $this->existingInvitation('000001', ['sent_at' => now()]);

        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001')]],
        ]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Sudah pernah dibuatkan CSAT', $output);
        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(0, EmailDelivery::count());
        $this->assertSame(2, CxpTicketSeen::count());
        Queue::assertNothingPushed();
    }

    public function test_all_pages_are_read_until_last_page(): void
    {
        $this->seedSnapshot('brand-a');

        $this->fakeStatusTickets([
            'brand-a' => [
                [$this->ticketStatus('000001')],
                [$this->ticketStatus('000002')],
                [$this->ticketStatus('000003')],
            ],
        ]);

        [$exitCode] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertEqualsCanonicalizing(
            ['000001', '000002', '000003'],
            Invitation::withoutGlobalScopes()->pluck('ticket_id')->all(),
        );
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'page=2'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'page=3'));
    }

    public function test_max_pages_limits_requests_per_project(): void
    {
        $this->seedSnapshot('brand-a');

        $this->fakeStatusTickets([
            'brand-a' => [
                [$this->ticketStatus('000001')],
                [$this->ticketStatus('000002')],
                [$this->ticketStatus('000003')],
            ],
        ]);

        [$exitCode] = $this->runCommand(['--send' => true, '--max-pages' => 1]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['000001'], Invitation::withoutGlobalScopes()->pluck('ticket_id')->all());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'page=2'));
    }

    public function test_each_workspace_uses_its_own_project_code_and_template(): void
    {
        [$brandB, $templateB] = $this->workspaceWithTemplate('brand-b', 'Brand B');

        $this->seedSnapshot('brand-a');
        $this->seedSnapshot('brand-b');

        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001')]],
            'brand-b' => [[$this->ticketStatus('000002')]],
        ]);

        [$exitCode] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);

        $invitationA = Invitation::withoutGlobalScopes()->where('ticket_id', '000001')->sole();
        $this->assertSame($this->workspace->id, $invitationA->workspace_id);
        $this->assertSame($this->template->id, $invitationA->survey_id);

        $invitationB = Invitation::withoutGlobalScopes()->where('ticket_id', '000002')->sole();
        $this->assertSame($brandB->id, $invitationB->workspace_id);
        $this->assertSame($templateB->id, $invitationB->survey_id);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'project=brand-a'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'project=brand-b'));
    }

    public function test_workspace_option_limits_which_project_is_called(): void
    {
        [$brandB] = $this->workspaceWithTemplate('brand-b', 'Brand B');

        $this->seedSnapshot('brand-a');
        $this->seedSnapshot('brand-b');

        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001')]],
            'brand-b' => [[$this->ticketStatus('000002')]],
        ]);

        [$exitCode] = $this->runCommand(['--send' => true, '--workspace' => 'brand-b']);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['000002'], Invitation::withoutGlobalScopes()->pluck('ticket_id')->all());
        $this->assertSame($brandB->id, Invitation::withoutGlobalScopes()->sole()->workspace_id);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'project=brand-a'));
    }

    public function test_unknown_workspace_code_aborts_before_login(): void
    {
        Http::fake();

        [$exitCode, $output] = $this->runCommand(['--workspace' => 'tidak-ada']);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('tidak ditemukan', $output);
        Http::assertNothingSent();
    }

    public function test_workspace_without_active_default_template_is_skipped(): void
    {
        $workspace = Workspace::create(['name' => 'Brand C', 'code' => 'brand-c']);
        $draft = $this->createTemplate($workspace, 'CSAT Brand C', 'draft');
        $workspace->default_csat_survey_id = $draft->id;
        $workspace->save();

        Http::fake();

        [$exitCode, $output] = $this->runCommand(['--workspace' => 'brand-c']);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('belum punya template CSAT aktif', $output);
        Http::assertNothingSent();
    }

    public function test_missing_credentials_abort_before_any_request(): void
    {
        config(['cxp.api.email' => '']);
        Http::fake();

        [$exitCode, $output] = $this->runCommand();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Kredensial API TransCXP belum lengkap', $output);
        Http::assertNothingSent();
    }

    public function test_one_project_failure_does_not_stop_the_other_projects(): void
    {
        [$brandB] = $this->workspaceWithTemplate('brand-b', 'Brand B');

        $this->seedSnapshot('brand-a');
        $this->seedSnapshot('brand-b');

        $this->fakeStatusTickets([
            'brand-b' => [[$this->ticketStatus('000002')]],
        ], ['brand-a']);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Project gagal diproses', $output);
        $this->assertSame(['000002'], Invitation::withoutGlobalScopes()->pluck('ticket_id')->all());
        $this->assertSame($brandB->id, Invitation::withoutGlobalScopes()->sole()->workspace_id);
        $this->assertSame(['seed'], CxpTicketSeen::where('project_code', 'brand-a')->pluck('ticket_id')->all());
    }

    public function test_ticket_option_bypasses_snapshot_and_still_stores_the_whole_baseline(): void
    {
        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001'), $this->ticketStatus('000002')]],
        ]);

        [$exitCode] = $this->runCommand([
            '--send' => true,
            '--workspace' => 'brand-a',
            '--ticket' => '000001',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['000001'], Invitation::withoutGlobalScopes()->pluck('ticket_id')->all());
        $this->assertSame(2, CxpTicketSeen::where('project_code', 'brand-a')->count());

        [$exitCode, $output] = $this->runCommand([
            '--send' => true,
            '--workspace' => 'brand-a',
            '--ticket' => '000001',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Sudah pernah dibuatkan CSAT', $output);
        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(1, EmailDelivery::count());
    }

    public function test_ticket_option_requires_a_workspace_so_it_cannot_scan_every_project(): void
    {
        Http::fake();

        [$exitCode, $output] = $this->runCommand(['--ticket' => '000001']);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('wajib disertai --workspace', $output);
        Http::assertNothingSent();
    }

    public function test_ticket_option_reports_a_ticket_that_is_not_found(): void
    {
        $this->seedSnapshot('brand-a');

        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001')]],
        ]);

        [$exitCode, $output] = $this->runCommand(['--workspace' => 'brand-a', '--ticket' => '999999']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Tiket tidak ditemukan', $output);
        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_invalid_whatsapp_is_not_saved_but_email_is_still_queued(): void
    {
        $this->seedSnapshot('brand-a');

        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001', 'sari@example.test', ['wa' => 'bukan-nomor'])]],
        ]);

        [$exitCode] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertNull(Client::withoutGlobalScopes()->sole()->wa);
        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
    }

    public function test_whatsapp_is_normalized_and_saved(): void
    {
        $this->seedSnapshot('brand-a');

        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001', 'sari@example.test', ['wa' => '0812-3456-7890'])]],
        ]);

        [$exitCode] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('6281234567890', Client::withoutGlobalScopes()->sole()->wa);
    }

    public function test_same_ticket_id_in_another_project_is_not_a_duplicate(): void
    {
        $this->existingInvitation('000001', ['sent_at' => now()]);
        [$brandB] = $this->workspaceWithTemplate('brand-b', 'Brand B');

        $this->seedSnapshot('brand-a');
        $this->seedSnapshot('brand-b');

        $this->fakeStatusTickets([
            'brand-a' => [[$this->ticketStatus('000001')]],
            'brand-b' => [[$this->ticketStatus('000001')]],
        ]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Sudah pernah dibuatkan CSAT', $output);
        $this->assertSame(2, Invitation::withoutGlobalScopes()->where('ticket_id', '000001')->count());
        $this->assertSame(1, Invitation::withoutGlobalScopes()
            ->where('workspace_id', $brandB->id)
            ->where('ticket_id', '000001')
            ->count());
    }

    public function test_limit_stops_after_the_requested_number_of_emails(): void
    {
        $this->seedSnapshot('brand-a');

        $this->fakeStatusTickets([
            'brand-a' => [[
                $this->ticketStatus('000001'),
                $this->ticketStatus('000002'),
                $this->ticketStatus('000003'),
            ]],
        ]);

        [$exitCode] = $this->runCommand(['--send' => true, '--limit' => 1]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
    }

    public function test_non_close_rows_are_ignored_even_if_the_api_returns_them(): void
    {
        $this->seedSnapshot('brand-a');

        $this->fakeStatusTickets([
            'brand-a' => [[
                $this->ticketStatus('000001', 'sari@example.test', ['status_ticket' => 'Open']),
                $this->ticketStatus('000002'),
            ]],
        ]);

        [$exitCode, $output] = $this->runCommand(['--send' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Bukan status Close', $output);
        $this->assertSame(['000002'], Invitation::withoutGlobalScopes()->pluck('ticket_id')->all());
        $this->assertFalse(CxpTicketSeen::where('ticket_id', '000001')->exists());
    }
}
