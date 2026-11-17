<?php

namespace Tests\Feature;

use App\Models\{CxpTicketSeen, EmailDelivery, Invitation, Question, Survey, User, WhatsAppDelivery, Workspace};
use App\Services\Csat\CsatChannelRouter;
use App\Services\Csat\CsatSettings;
use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Artisan, Http, Queue};
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Fase 6b: kirim CSAT lewat WhatsApp TransCXP dengan fallback email.
 *
 * Semua panggilan HTTP dibatasi dengan Http::fake; tidak ada kredensial asli
 * dan tidak ada pesan yang benar-benar terkirim selama test.
 */
class CxpCsatWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Survey $template;

    private User $admin;

    /** @var array<int, string> */
    private array $sentMessageIds = [];

    /** @var array<int, array<string, mixed>> */
    private array $tickets = [];

    /** @var array<string, mixed> */
    private array $behavior = [];

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
            'cxp.csat.wa_sources' => ['WhatsApp'],
            'cxp.csat.email_sources' => ['Email'],
            'cxp.csat.email_fallback_sources' => ['Instagram', 'Facebook', 'Google_business'],
            'cxp.csat.skip_sources' => ['Call', 'Outbound Call', 'WAG'],
            'cxp.csat.default_channel' => 'email',
            'cxp.whatsapp.base_url' => 'https://wa.example.test',
            'cxp.whatsapp.timeout' => 5,
            'cxp.whatsapp.login_path' => '/api/v2/login-user',
            'cxp.whatsapp.send_path' => '/api/v2/messages-template',
            'cxp.whatsapp.status_path' => '/api/v2/messages-template',
            'cxp.whatsapp.template_id' => 'tpl-csat-1',
            'cxp.whatsapp.template_params' => ['customer_name', 'survey_url'],
            'cxp.whatsapp.button_url_param' => '',
            'cxp.whatsapp.accounts' => json_encode([
                'brand-a' => [
                    'username' => 'wa-user',
                    'password' => 'wa-password',
                    'number' => '+6281110877088',
                ],
            ]),
            'cxp.whatsapp.accounts_file' => null,
            'cxp.whatsapp.token_cache_ttl' => 3600,
            'survey.email_enabled' => true,
            'mail.default' => 'smtp',
        ]);

        Queue::fake();
        // Satu stub untuk seluruh test; fakeCxp() hanya mengganti data yang
        // dikembalikan supaya Http::fake() tidak menumpuk callback.
        Http::fake(fn (Request $request) => $this->handleCxpRequest($request));

        $this->workspace = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);
        $this->workspace->settings = ['csat' => ['expiry_unit' => 'days', 'expiry_value' => 7]];
        $this->workspace->save();

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

    private function createTemplate(Workspace $workspace, string $title): Survey
    {
        $template = new Survey([
            'title' => $title,
            'description' => 'Bantu kami meningkatkan layanan.',
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

        return $template;
    }

    /**
     * Atur data yang dikembalikan stub TransCXP (tiket dan WhatsApp).
     *
     * @param  array<int, array<string, mixed>>  $tickets
     * @param  array{send_status?: int, status_status?: int, send_message?: string, delivered?: bool}  $behavior
     */
    private function fakeCxp(array $tickets, array $behavior = []): void
    {
        $this->tickets = $tickets;
        $this->behavior = $behavior;
    }

    private function handleCxpRequest(Request $request)
    {
        $url = $request->url();
        $method = $request->method();
        $sendStatus = (int) ($this->behavior['send_status'] ?? 200);
        $statusStatus = (int) ($this->behavior['status_status'] ?? 200);
        $sendMessage = (string) ($this->behavior['send_message'] ?? 'Template tidak valid');
        $delivered = (bool) ($this->behavior['delivered'] ?? false);

        if (str_contains($url, '/api/v1/login-user') || str_contains($url, '/api/v2/login-user')) {
            return Http::response([
                'statusCode' => 200,
                'userInfo' => [
                    'name' => 'API User',
                    'email' => 'api-user@example.test',
                    'token' => 'token-rahasia-tidak-boleh-tampil',
                    'expired_date' => now()->addYear()->toDateTimeString(),
                ],
            ], 200);
        }

        if (str_contains($url, '/api/v1/dataTicketByStatus')) {
            return Http::response([
                'status' => 'success',
                'code' => 200,
                'message' => 'ok',
                'data' => array_values($this->tickets),
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 100,
                    'total' => count($this->tickets),
                    'last_page' => 1,
                ],
            ], 200);
        }

        if (str_ends_with($url, '/api/v2/messages-template') && $method === 'POST') {
            if ($sendStatus >= 400) {
                return Http::response(['message' => $sendMessage], $sendStatus);
            }

            $messageId = 'wamid-'.(count($this->sentMessageIds) + 1);
            $this->sentMessageIds[] = $messageId;

            return Http::response([
                'status' => 'success',
                'data' => [
                    'message_id' => $messageId,
                    'recipient_number' => $request['recipient_contact']['recipient_number'] ?? '',
                ],
            ], 200);
        }

        if (str_contains($url, '/api/v2/messages-template/') && $method === 'GET') {
            if ($statusStatus >= 400) {
                return Http::response(['message' => 'Server error'], $statusStatus);
            }

            $messageId = basename((string) parse_url($url, PHP_URL_PATH));

            return Http::response([
                'status' => 'success',
                'data' => [
                    'message_id' => $messageId,
                    'status' => $delivered ? 'delivered' : 'sent',
                    'sent_at' => now()->subMinute()->toDateTimeString(),
                    'delivered_at' => $delivered ? now()->toDateTimeString() : null,
                ],
            ], 200);
        }

        return Http::response(['message' => 'Endpoint tidak dikenal'], 404);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ticket(string $id, array $overrides = []): array
    {
        return array_merge([
            'id_ticket' => $id,
            'id_project' => 'brand-a',
            'project_name' => 'Brand A',
            'name_customer' => 'Sari',
            'email' => null,
            'wa' => '0811-1087-7088',
            'phone_no' => null,
            'mobile_phone' => null,
            'status_ticket' => 'Close',
            'source' => 'WhatsApp',
        ], $overrides);
    }

    private function seedSnapshot(string $projectCode = 'brand-a'): void
    {
        CxpTicketSeen::create([
            'project_code' => $projectCode,
            'ticket_id' => 'seed',
            'first_seen_at' => now(),
        ]);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function send(array $options = ['--send' => true]): array
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-send', $options, $output);

        return [$exitCode, $output->fetch()];
    }

    private function lastWhatsAppRequest(): ?Request
    {
        $found = null;

        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/v2/messages-template')) {
                $found = $request;
            }
        }

        return $found;
    }

    private function countWhatsAppSends(): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair) => $pair[0]->method() === 'POST'
                && str_ends_with($pair[0]->url(), '/api/v2/messages-template'))
            ->count();
    }

    public function test_whatsapp_source_sends_template_with_survey_link_and_marks_invitation_sent(): void
    {
        $this->fakeCxp([$this->ticket('WA-1')]);
        $this->seedSnapshot();

        [$exitCode, $output] = $this->send();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('WhatsApp dikirim', $output);

        $request = $this->lastWhatsAppRequest();
        $this->assertNotNull($request);
        $this->assertSame('tpl-csat-1', $request['template_id']);
        $this->assertSame('+6281110877088', $request['recipient_contact']['recipient_number']);
        $this->assertSame('Sari', $request['recipient_contact']['name']);

        $body = collect($request['recipient_contact']['components'])
            ->firstWhere('type', 'body');
        $this->assertNotNull($body);
        $this->assertSame('Sari', $body['parameters'][0]['text']);
        $this->assertStringContainsString('/s/', $body['parameters'][1]['text']);

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', 'WA-1')->firstOrFail();
        $this->assertNotNull($invitation->sent_at);
        $this->assertNotNull($invitation->expires_at);
        $this->assertTrue($invitation->expires_at->greaterThan(now()->addDays(6)));

        $delivery = WhatsAppDelivery::query()->where('invitation_id', $invitation->id)->firstOrFail();
        $this->assertSame(WhatsAppDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame('wamid-1', $delivery->provider_message_id);
        $this->assertSame('+6281110877088', $delivery->sender_number);
        $this->assertSame('+6281110877088', $delivery->recipient_number);

        // Kanal WhatsApp tidak boleh juga mengantrekan email.
        $this->assertSame(0, EmailDelivery::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_whatsapp_source_without_account_falls_back_to_email(): void
    {
        config(['cxp.whatsapp.accounts' => json_encode([])]);

        $this->fakeCxp([$this->ticket('WA-2', ['email' => 'sari@example.test'])]);
        $this->seedSnapshot();

        [$exitCode] = $this->send();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $this->countWhatsAppSends());

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', 'WA-2')->firstOrFail();
        $this->assertSame('sari@example.test', $invitation->recipient_email);
        $this->assertSame(1, EmailDelivery::query()->count());
        $this->assertSame(0, WhatsAppDelivery::query()->count());
    }

    public function test_whatsapp_source_without_number_or_email_is_skipped(): void
    {
        $this->fakeCxp([$this->ticket('WA-3', ['wa' => null])]);
        $this->seedSnapshot();

        [$exitCode, $output] = $this->send();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dilewati menurut kanal', $output);
        $this->assertSame(0, Invitation::withoutGlobalScopes()->where('ticket_id', 'WA-3')->count());
    }

    public function test_email_source_uses_email_even_when_whatsapp_number_exists(): void
    {
        $this->fakeCxp([$this->ticket('EM-1', ['source' => 'Email', 'email' => 'sari@example.test'])]);
        $this->seedSnapshot();

        [$exitCode] = $this->send();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $this->countWhatsAppSends());
        $this->assertSame('sari@example.test', Invitation::withoutGlobalScopes()->where('ticket_id', 'EM-1')->value('recipient_email'));
    }

    public function test_call_and_wag_sources_are_skipped_entirely(): void
    {
        $this->fakeCxp([
            $this->ticket('CALL-1', ['source' => 'Call', 'email' => 'call@example.test']),
            $this->ticket('WAG-1', ['source' => 'WAG', 'email' => 'wag@example.test', 'wa' => '0811111111']),
        ]);
        $this->seedSnapshot();

        [$exitCode] = $this->send();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(0, EmailDelivery::query()->count());
        $this->assertSame(0, WhatsAppDelivery::query()->count());
    }

    public function test_instagram_and_google_business_use_email_fallback(): void
    {
        $this->fakeCxp([
            $this->ticket('IG-1', ['source' => 'Instagram', 'email' => 'ig@example.test']),
            $this->ticket('GB-1', ['source' => 'Google_business', 'email' => 'gb@example.test']),
        ]);
        $this->seedSnapshot();

        [$exitCode] = $this->send();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $this->countWhatsAppSends());
        $this->assertSame(2, Invitation::withoutGlobalScopes()->count());
    }

    public function test_repeated_runs_do_not_send_a_second_whatsapp_message(): void
    {
        $this->fakeCxp([$this->ticket('WA-4')]);
        $this->seedSnapshot();

        $this->send();
        $this->assertSame(1, $this->countWhatsAppSends());

        // Hapus snapshot supaya dedupe diuji lewat data undangan/delivery.
        CxpTicketSeen::query()->delete();

        [$exitCode, $output] = $this->send();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $this->countWhatsAppSends());
        $this->assertSame(1, WhatsAppDelivery::query()->count());
        $this->assertStringContainsString('Sudah pernah dibuatkan CSAT', $output);
    }

    public function test_permanent_whatsapp_rejection_falls_back_to_email(): void
    {
        $this->fakeCxp(
            [$this->ticket('WA-5', ['email' => 'sari@example.test'])],
            ['send_status' => 400, 'send_message' => 'Nomor tidak terdaftar'],
        );
        $this->seedSnapshot();

        [$exitCode] = $this->send();

        $this->assertSame(0, $exitCode);

        $delivery = WhatsAppDelivery::query()->firstOrFail();
        $this->assertSame(WhatsAppDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('Nomor tidak terdaftar', $delivery->failure_reason);

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', 'WA-5')->firstOrFail();
        $this->assertSame('sari@example.test', $invitation->recipient_email);
        $this->assertSame(1, EmailDelivery::query()->count());
    }

    public function test_transient_whatsapp_failure_is_retried_on_the_next_run(): void
    {
        $this->fakeCxp([$this->ticket('WA-6')], ['send_status' => 500, 'send_message' => 'Server error']);
        $this->seedSnapshot();

        [$exitCode, $output] = $this->send();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('WhatsApp gagal', $output);
        $this->assertSame(WhatsAppDelivery::STATUS_FAILED, WhatsAppDelivery::query()->firstOrFail()->status);

        // Gangguan sementara tidak boleh masuk snapshot.
        $this->assertSame(0, CxpTicketSeen::query()->where('ticket_id', 'WA-6')->count());

        $this->fakeCxp([$this->ticket('WA-6')]);

        [$exitCode, $secondOutput] = $this->send();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, WhatsAppDelivery::query()->count());
        $this->assertSame(WhatsAppDelivery::STATUS_SENT, WhatsAppDelivery::query()->firstOrFail()->status, $secondOutput);
    }

    public function test_status_command_updates_delivery_and_invitation_expiry(): void
    {
        $this->fakeCxp([$this->ticket('WA-7')]);
        $this->seedSnapshot();
        $this->send();

        $delivery = WhatsAppDelivery::query()->firstOrFail();
        // Simulasikan undangan yang sent_at-nya belum tercatat.
        $invitation = $delivery->invitation;
        $invitation->sent_at = null;
        $invitation->expires_at = null;
        $invitation->save();

        $this->fakeCxp([], ['delivered' => true]);

        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-wa-status', ['--limit' => 10], $output);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Diperbarui', $output->fetch());

        $delivery->refresh();
        $this->assertSame(WhatsAppDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertNotNull($delivery->delivered_at);

        $invitation->refresh();
        $this->assertNotNull($invitation->sent_at);
        $this->assertNotNull($invitation->expires_at);
    }

    public function test_router_maps_sources_and_missing_accounts(): void
    {
        $router = app(CsatChannelRouter::class);

        $this->assertSame(
            CsatChannelRouter::CHANNEL_WHATSAPP,
            $router->route('WhatsApp', null, '0811-1087-7088', $this->workspace)['channel'],
        );
        $this->assertSame(
            CsatChannelRouter::CHANNEL_EMAIL,
            $router->route('WhatsApp', 'sari@example.test', null, $this->workspace)['channel'],
        );
        $this->assertSame(
            CsatChannelRouter::CHANNEL_SKIP,
            $router->route('WhatsApp', null, null, $this->workspace)['channel'],
        );
        $this->assertSame(
            CsatChannelRouter::CHANNEL_EMAIL,
            $router->route('Email', 'sari@example.test', '0811-1087-7088', $this->workspace)['channel'],
        );
        $this->assertSame(
            CsatChannelRouter::CHANNEL_SKIP,
            $router->route('Email', null, '0811-1087-7088', $this->workspace)['channel'],
        );
        $this->assertSame(
            CsatChannelRouter::CHANNEL_EMAIL,
            $router->route('Instagram', 'sari@example.test', null, $this->workspace)['channel'],
        );
        $this->assertSame(
            CsatChannelRouter::CHANNEL_SKIP,
            $router->route('Instagram', null, '0811-1087-7088', $this->workspace)['channel'],
        );
        $this->assertSame(
            CsatChannelRouter::CHANNEL_SKIP,
            $router->route('Outbound Call', 'sari@example.test', null, $this->workspace)['channel'],
        );

        // Nama source tidak peka huruf besar/spasi/garis bawah.
        $this->assertSame(
            CsatChannelRouter::CHANNEL_WHATSAPP,
            $router->route(' whatsapp ', null, '0811-1087-7088', $this->workspace)['channel'],
        );
    }

    public function test_whatsapp_route_falls_back_to_email_when_workspace_has_no_account(): void
    {
        $other = Workspace::create(['name' => 'Brand B', 'code' => 'brand-b']);
        $router = app(CsatChannelRouter::class);

        $route = $router->route('WhatsApp', 'sari@example.test', '0811-1087-7088', $other);

        $this->assertSame(CsatChannelRouter::CHANNEL_EMAIL, $route['channel']);
        $this->assertSame('fallback_email', $route['reason']);
    }

    public function test_autosend_setting_keeps_other_csat_settings(): void
    {
        $this->workspace->settings = ['other_setting' => 'dipertahankan', 'csat' => [
            'expiry_unit' => 'days',
            'expiry_value' => 7,
            'send_mode' => 'auto',
            'autosend' => false,
        ]];
        $this->workspace->save();

        $operation = User::create([
            'name' => 'Operation',
            'email' => 'operation@example.test',
            'password' => 'OnlyTesting123!',
            'role' => 'operation',
        ]);
        $operation->workspaces()->attach($this->workspace);

        $this->actingAs($operation)
            ->withSession([CurrentWorkspace::SESSION_KEY => $this->workspace->id])
            ->put(route('csat.settings.update'), [
                'expiry_unit' => 'days',
                'expiry_value' => 5,
                'autosend' => '1',
            ])
            ->assertRedirect();

        $settings = $this->workspace->fresh()->settings;
        $this->assertSame('dipertahankan', $settings['other_setting']);
        $this->assertSame('auto', $settings['csat']['send_mode']);
        $this->assertTrue($settings['csat']['autosend']);
        $this->assertSame(5, $settings['csat']['expiry_value']);
        $this->assertTrue(CsatSettings::autosend($this->workspace->fresh()));
    }

    public function test_autosend_gate_only_processes_workspaces_that_opt_in(): void
    {
        config(['cxp.csat.enabled' => true]);

        $this->workspace->settings = ['csat' => ['expiry_unit' => 'days', 'expiry_value' => 7, 'autosend' => false]];
        $this->workspace->save();

        $this->fakeCxp([$this->ticket('WA-8')]);
        $this->seedSnapshot();

        [$exitCode, $output] = $this->send([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $this->countWhatsAppSends());
        $this->assertStringContainsString('autosend belum diaktifkan', $output);
    }

    public function test_onboard_dry_run_writes_nothing(): void
    {
        config([
            'cxp.csat.projects' => ['4' => 'ABC', '5' => 'Transcosmos Indonesia'],
            'cxp.csat.pilot_projects' => ['4'],
            'cxp.csat.master_template_id' => 0,
        ]);

        $before = Workspace::query()->count();

        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-onboard', [
            '--projects' => '4,5',
            '--from' => $this->template->id,
            '--dry-run' => true,
        ], $output);

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, Workspace::query()->count());
        $this->assertSame(0, Workspace::query()->whereIn('code', ['4', '5'])->count());
        $this->assertStringContainsString('DRY-RUN', $output->fetch());
    }

    public function test_onboard_creates_workspace_and_clones_csat_template(): void
    {
        config([
            'cxp.csat.projects' => ['4' => 'ABC', '5' => 'Transcosmos Indonesia'],
            'cxp.csat.pilot_projects' => ['4'],
            'cxp.csat.master_template_id' => 0,
        ]);

        Question::create([
            'survey_id' => $this->template->id,
            'text' => 'Seberapa puas Anda?',
            'category' => 'Kepuasan',
            'type' => 'rating',
            'required' => true,
            'position' => 1,
        ]);

        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-onboard', [
            '--projects' => '4,5',
            '--from' => $this->template->id,
            '--expiry-days' => 7,
            '--pilot' => true,
        ], $output);

        $this->assertSame(0, $exitCode);

        $pilot = Workspace::query()->where('code', '4')->firstOrFail();
        $this->assertSame('ABC', $pilot->name);
        $this->assertTrue(CsatSettings::autosend($pilot));
        $this->assertSame('days', $pilot->settings['csat']['expiry_unit']);
        $this->assertSame(7, $pilot->settings['csat']['expiry_value']);

        $cloned = Survey::withoutGlobalScopes()->findOrFail($pilot->default_csat_survey_id);
        $this->assertSame('csat', $cloned->type);
        $this->assertSame($pilot->id, $cloned->workspace_id);
        $this->assertSame(1, $cloned->questions()->withoutGlobalScopes()->count());

        $other = Workspace::query()->where('code', '5')->firstOrFail();
        $this->assertFalse(CsatSettings::autosend($other));
        $this->assertNotNull($other->default_csat_survey_id);
        $this->assertNotSame($pilot->default_csat_survey_id, $other->default_csat_survey_id);
    }

    public function test_onboard_keeps_existing_template_unless_forced(): void
    {
        config([
            'cxp.csat.projects' => ['4' => 'ABC'],
            'cxp.csat.master_template_id' => 0,
        ]);

        $output = new BufferedOutput();
        Artisan::call('cxp:csat-onboard', [
            '--projects' => '4',
            '--from' => $this->template->id,
        ], $output);

        $workspace = Workspace::query()->where('code', '4')->firstOrFail();
        $firstTemplate = $workspace->default_csat_survey_id;

        Artisan::call('cxp:csat-onboard', [
            '--projects' => '4',
            '--from' => $this->template->id,
        ], new BufferedOutput());

        $workspace->refresh();
        $this->assertSame($firstTemplate, $workspace->default_csat_survey_id);
        $this->assertSame(1, Survey::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('type', 'csat')->count());

        Artisan::call('cxp:csat-onboard', [
            '--projects' => '4',
            '--from' => $this->template->id,
            '--force' => true,
        ], new BufferedOutput());

        $workspace->refresh();
        $this->assertNotSame($firstTemplate, $workspace->default_csat_survey_id);
        $this->assertSame(2, Survey::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('type', 'csat')->count());
    }

    public function test_onboard_pilot_requires_configured_projects(): void
    {
        config([
            'cxp.csat.projects' => ['4' => 'ABC'],
            'cxp.csat.pilot_projects' => [],
        ]);

        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-onboard', ['--pilot' => true], $output);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('CXP_CSAT_PILOT_PROJECTS', $output->fetch());
    }

    public function test_wa_only_invitation_url_is_stable_between_retries(): void
    {
        $this->fakeCxp([$this->ticket('WA-9')]);
        $this->seedSnapshot();

        $this->send();
        $first = Invitation::withoutGlobalScopes()->where('ticket_id', 'WA-9')->firstOrFail();

        CxpTicketSeen::query()->delete();
        $this->send();

        $second = Invitation::withoutGlobalScopes()->where('ticket_id', 'WA-9')->firstOrFail();

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->surveyUrl(), $second->surveyUrl());
        $this->assertNotNull(Str::after($first->surveyUrl(), '/s/'));
    }
}
