<?php

namespace Tests\Feature;

use App\Models\{CxpTicketSeen, Invitation, Survey, User, WhatsAppDelivery, Workspace};
use App\Services\Csat\CsatSettings;
use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Artisan, Http, Queue};
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * UI CSAT per workspace: metode manual/auto, status tiket, kanal, tautan,
 * dan status pengisian di halaman Respons.
 */
class CsatWorkspaceUiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $operation;
    private Survey $template;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cxp.api.base_url' => 'https://cxp.example.test',
            'cxp.api.email' => 'api-user@example.test',
            'cxp.api.password' => 'password-rahasia',
            'cxp.api.status_path' => '/api/v1/dataTicketByStatus',
            'cxp.csat.enabled' => false,
            'cxp.csat.workspace' => '',
            'cxp.csat.per_page' => 100,
            'cxp.csat.max_pages' => 0,
            'cxp.csat.disabled_projects' => [],
            'cxp.csat.email_only_projects' => [],
            'cxp.csat.wa_sources' => ['WhatsApp'],
            'cxp.csat.email_sources' => ['Email'],
            'cxp.csat.email_fallback_sources' => [],
            'cxp.csat.skip_sources' => [],
            'cxp.csat.default_channel' => 'email',
            'cxp.whatsapp.base_url' => 'https://wa.example.test',
            'cxp.whatsapp.timeout' => 5,
            'cxp.whatsapp.login_path' => '/api/v2/login-user',
            'cxp.whatsapp.send_path' => '/api/v2/messages-template',
            'cxp.whatsapp.template_id' => 'tpl-csat-ui',
            'cxp.whatsapp.template_params' => ['customer_name', 'survey_url'],
            'cxp.whatsapp.button_url_param' => '',
            'cxp.whatsapp.accounts' => json_encode([
                'brand-a' => [
                    'username' => 'wa-user@example.test',
                    'password' => 'rahasia',
                    'number' => '+6281110877088',
                ],
            ]),
            'cxp.whatsapp.accounts_file' => null,
            'survey.email_enabled' => true,
            'mail.default' => 'smtp',
            'mail.mailers.csat.transport' => 'smtp',
            'mail.mailers.csat.host' => 'smtp-csat.example.test',
            'mail.mailers.csat.port' => 587,
            'mail.mailers.csat.username' => 'csat-user',
            'mail.mailers.csat.password' => 'csat-password',
            'mail.mailers.csat.from.address' => 'csat@example.test',
            'mail.mailers.csat.from.name' => 'CSAT TransSurvey',
        ]);

        Queue::fake();

        $this->workspace = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);
        $this->operation = User::create([
            'name' => 'Operation',
            'email' => 'operation@example.test',
            'password' => 'OnlyTesting123!',
            'role' => 'operation',
        ]);
        $this->operation->workspaces()->attach($this->workspace);

        $this->template = new Survey([
            'title' => 'CSAT Standar',
            'type' => 'csat',
            'status' => 'active',
            'starts_at' => today(),
            'ends_at' => null,
            'is_template' => true,
            'version' => 1,
            'created_by' => $this->operation->id,
        ]);
        $this->template->workspace_id = $this->workspace->id;
        $this->template->save();

        $this->workspace->default_csat_survey_id = $this->template->id;
        $this->workspace->save();
    }

    private function acting(): self
    {
        return $this->actingAs($this->operation)
            ->withSession([CurrentWorkspace::SESSION_KEY => $this->workspace->id]);
    }

    public function test_settings_page_exposes_method_status_and_channels(): void
    {
        $this->acting()->get(route('csat.settings.edit'))
            ->assertOk()
            ->assertSee('name="send_mode"', false)
            ->assertSee('name="auto_statuses"', false)
            ->assertSee('name="auto_channels[]"', false);
    }

    public function test_operation_can_save_method_status_and_channels(): void
    {
        $this->acting()->put(route('csat.settings.update'), [
            'expiry_unit' => 'days',
            'expiry_value' => 7,
            'send_mode' => 'both',
            'auto_statuses' => 'Close, Resolved',
            'auto_channels' => ['email', 'whatsapp'],
            'autosend' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $settings = CsatSettings::for($this->workspace->fresh());

        $this->assertSame('both', $settings['send_mode']);
        $this->assertSame(['Close', 'Resolved'], $settings['statuses']);
        $this->assertSame(['email', 'whatsapp'], $settings['channels']);
        $this->assertTrue(CsatSettings::autosend($this->workspace->fresh()));
    }

    public function test_email_only_workspace_cannot_enable_whatsapp_from_ui(): void
    {
        config(['cxp.csat.email_only_projects' => ['brand-a']]);

        $this->acting()->put(route('csat.settings.update'), [
            'expiry_unit' => 'days',
            'expiry_value' => 7,
            'send_mode' => 'auto',
            'auto_statuses' => 'Close',
            'auto_channels' => ['whatsapp'],
            'autosend' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['email'], CsatSettings::channels($this->workspace->fresh()));
    }

    public function test_legacy_autosend_without_send_mode_stays_enabled(): void
    {
        $this->workspace->settings = ['csat' => [
            'expiry_unit' => 'days',
            'expiry_value' => 7,
            'autosend' => true,
        ]];
        $this->workspace->save();

        $this->assertTrue(CsatSettings::autosend($this->workspace->fresh()));
    }

    public function test_autosend_uses_ticket_status_from_workspace_settings(): void
    {
        $this->workspace->settings = ['csat' => [
            'expiry_unit' => 'days',
            'expiry_value' => 7,
            'send_mode' => 'auto',
            'autosend' => true,
            'auto_statuses' => ['Resolved'],
            'auto_channels' => ['email'],
        ]];
        $this->workspace->save();

        CxpTicketSeen::create([
            'project_code' => 'brand-a',
            'ticket_id' => 'RES-0',
            'first_seen_at' => now(),
        ]);

        $requestedStatuses = [];
        Http::fake(function (Request $request) use (&$requestedStatuses) {
            if (str_contains($request->url(), '/login-user')) {
                return Http::response([
                    'statusCode' => 200,
                    'userInfo' => ['token' => 'token-uji', 'expired_date' => now()->addDay()->toDateTimeString()],
                ], 200);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $requestedStatuses[] = (string) ($query['status'] ?? '');

            return Http::response([
                'status' => 'success',
                'data' => [
                    ['id_ticket' => 'RES-0', 'status_ticket' => 'Resolved', 'name_customer' => 'Lama', 'email' => 'lama@example.test', 'source' => 'Email'],
                    ['id_ticket' => 'RES-1', 'status_ticket' => 'Resolved', 'name_customer' => 'Baru', 'email' => 'baru@example.test', 'source' => 'Email'],
                ],
                'pagination' => ['last_page' => 1],
            ], 200);
        });

        $output = new BufferedOutput();
        $exitCode = Artisan::call('cxp:csat-send', [
            '--send' => true,
            '--only-autosend' => true,
        ], $output);

        $this->assertSame(0, $exitCode, $output->fetch());
        $this->assertContains('Resolved', $requestedStatuses);
        $this->assertNotContains('Close', $requestedStatuses);
        $this->assertSame(['RES-1'], Invitation::withoutGlobalScopes()->pluck('ticket_id')->all());
        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', 'RES-1')->firstOrFail();
        $this->assertSame('Resolved', $invitation->ticket_status);
    }

    public function test_manual_send_without_sso_context_creates_link(): void
    {
        $response = $this->acting()->post(route('csat.send.store'), [
            'ticket_id' => 'MAN-1',
            'ticket_status' => 'Solved',
            'customer_name' => 'Sari',
            'customer_email' => 'sari@example.test',
            'channel' => 'email',
            'template_id' => $this->template->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', 'MAN-1')->firstOrFail();
        $response->assertSessionHas('csat_link', $invitation->surveyUrl());
        $this->assertSame('email', $invitation->channel);
        $this->assertSame('Solved', $invitation->ticket_status);

        $this->acting()->get(route('csat.send.index'))
            ->assertOk()
            ->assertSee('Tautan survey siap dipakai')
            ->assertSee('Salin tautan');
    }

    public function test_manual_whatsapp_send_uses_workspace_account(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/login-user')) {
                return Http::response([
                    'statusCode' => 200,
                    'userInfo' => [
                        'token' => 'wa-token-uji',
                        'expired_date' => now()->addDay()->toDateTimeString(),
                    ],
                ], 200);
            }

            if (str_contains($request->url(), '/messages-template')) {
                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'message_id' => 'wamid-ui-1',
                        'recipient_number' => $request['recipient_contact']['recipient_number'] ?? '',
                    ],
                ], 200);
            }

            return Http::response(['message' => 'Not found'], 404);
        });

        $this->acting()->post(route('csat.send.store'), [
            'ticket_id' => 'WA-UI-1',
            'ticket_status' => 'Solved',
            'customer_name' => 'Sari',
            'customer_wa' => '+6287770187708',
            'channel' => 'whatsapp',
            'template_id' => $this->template->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', 'WA-UI-1')->firstOrFail();
        $delivery = WhatsAppDelivery::where('invitation_id', $invitation->id)->firstOrFail();

        $this->assertSame(WhatsAppDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame('wamid-ui-1', $delivery->provider_message_id);
        $this->assertNotNull($invitation->sent_at);
    }

    public function test_responses_page_shows_csat_completion_status_and_filter(): void
    {
        $this->acting();

        Invitation::create([
            'survey_id' => $this->template->id,
            'client_id' => $this->client()->id,
            'ticket_id' => 'CSAT-DONE',
            'ticket_status' => 'Solved',
            'channel' => 'email',
            'token' => $token = Str::random(64),
            'token_hash' => hash('sha256', $token),
            'recipient_name' => 'Sari',
            'recipient_email' => 'sari@example.test',
            'completed_at' => now(),
            'sent_at' => now()->subDay(),
            'is_demo' => false,
        ]);

        $this->get(route('responses.index'))
            ->assertOk()
            ->assertSee('Status pengisian CSAT')
            ->assertSee('CSAT-DONE')
            ->assertSee('Sudah diisi');

        $this->get(route('responses.index', ['csat_status' => 'completed']))
            ->assertOk()
            ->assertSee('CSAT-DONE');

        $this->get(route('responses.index', ['csat_status' => 'draft']))
            ->assertOk()
            ->assertDontSee('CSAT-DONE');
    }

    private function client(): \App\Models\Client
    {
        $client = new \App\Models\Client([
            'name' => 'Brand A',
            'contact' => 'Sari',
            'email' => 'sari@example.test',
            'project' => 'Brand A',
            'active' => true,
        ]);
        $client->workspace_id = $this->workspace->id;
        $client->save();

        return $client;
    }
}
