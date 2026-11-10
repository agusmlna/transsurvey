<?php

namespace Tests\Feature;

use App\Jobs\{SendSurveyCompletionEmail, SendSurveyEmail};
use App\Mail\{CsatInvitationMail, SurveyCompletedMail, SurveyInvitationMail};
use App\Models\{AdminSurveyNotification, Client, EmailDelivery, Invitation, Survey, SurveyResponse, User, Workspace};
use App\Services\{CurrentWorkspace, ResponseService};
use App\Services\Cxp\CxpLaunchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Mail, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class CsatSendTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $operation;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'survey.email_enabled' => true,
            'mail.default' => 'smtp',
            'survey.csat_notification_roles' => ['operation', 'superadmin'],
            'cxp.launch_api_key' => 'test-launch-key',
            'cxp.launch_code_ttl' => 120,
            'cxp.external_source' => 'transcxp',
        ]);

        Mail::fake();
        Queue::fake();
        Cache::flush();

        $this->workspace = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);
        $this->operation = $this->user('operation', $this->workspace);
    }

    private function user(string $role, ?Workspace $workspace = null): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . Str::random(3),
            'email' => $role . Str::random(5) . '@example.test',
            'password' => 'OnlyTesting123!',
            'role' => $role,
        ]);

        if ($workspace) {
            $user->workspaces()->attach($workspace);
        }

        return $user;
    }

    private function template(?Workspace $workspace = null, array $attributes = []): Survey
    {
        $workspace ??= $this->workspace;

        $template = new Survey(array_merge([
            'title' => 'Survei Kepuasan Layanan',
            'description' => 'Bantu kami meningkatkan layanan.',
            'type' => 'csat',
            'status' => 'active',
            'starts_at' => today(),
            'ends_at' => null,
            'is_template' => true,
            'version' => 1,
            'created_by' => $this->operation->id,
        ], $attributes));
        $template->workspace_id = $workspace->id;
        $template->save();

        $template->questions()->create([
            'text' => 'Seberapa puas Anda dengan penanganan tiket ini?',
            'category' => 'Kepuasan',
            'type' => 'rating',
            'required' => true,
            'position' => 1,
        ]);

        return $template;
    }

    private function context(array $overrides = []): array
    {
        return array_replace_recursive([
            'workspace_id' => $this->workspace->id,
            'ticket' => [
                'id' => 'TCK-1',
                'status' => 'solved',
                'channel' => 'email',
            ],
            'customer' => [
                'id' => 'CUST-1',
                'name' => 'Sari',
                'email' => 'sari@example.test',
            ],
        ], $overrides);
    }

    private function asOperation(?array $context = null): self
    {
        $session = [CurrentWorkspace::SESSION_KEY => $this->workspace->id];

        if ($context !== null) {
            $session[CxpLaunchContext::SESSION_KEY] = $context;
        }

        return $this->actingAs($this->operation)->withSession($session);
    }

    private function invitationFor(Survey $template, array $attributes = []): Invitation
    {
        $client = new Client([
            'name' => 'Sari',
            'contact' => 'Sari',
            'email' => 'sari@example.test',
            'project' => 'Brand A',
            'active' => true,
        ]);
        $client->workspace_id = $template->workspace_id;
        $client->save();

        $token = Str::random(64);

        $invitation = Invitation::create(array_merge([
            'survey_id' => $template->id,
            'client_id' => $client->id,
            'ticket_id' => 'TCK-1',
            'ticket_status' => 'solved',
            'channel' => 'email',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'recipient_name' => 'Sari',
            'recipient_email' => 'sari@example.test',
            'is_demo' => false,
        ], $attributes));

        return $invitation;
    }

    public function test_operation_can_send_csat_from_ticket_without_access_code(): void
    {
        $template = $this->template();

        $this->asOperation($this->context())
            ->post(route('csat.send.store'), ['template_id' => $template->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', 'TCK-1')->sole();

        $this->assertSame($this->workspace->id, $invitation->workspace_id);
        $this->assertSame('solved', $invitation->ticket_status);
        $this->assertSame('email', $invitation->channel);
        $this->assertSame('sari@example.test', $invitation->recipient_email);
        $this->assertNull($invitation->sent_at);
        $this->assertNull($invitation->expires_at);
        $this->assertNull($invitation->access_code_hash);

        $delivery = $invitation->deliveries()->sole();
        Queue::assertPushed(SendSurveyEmail::class);

        (new SendSurveyEmail($delivery->id))->handle();

        Mail::assertSent(CsatInvitationMail::class, fn ($mail) => $mail->hasTo('sari@example.test')
            && $mail->invitation->is($invitation));
        Mail::assertNotSent(SurveyInvitationMail::class);

        $invitation->refresh();
        $this->assertNotNull($invitation->sent_at);
        $this->assertNull($invitation->expires_at);
        $this->assertNull($invitation->access_code_hash);

        $html = (new CsatInvitationMail($invitation))->render();
        $this->assertStringContainsString('TCK-1', $html);
        $this->assertStringContainsString(route('survey.public', $invitation->token), $html);
        $this->assertStringNotContainsString('Kode akses', $html);
        $this->assertStringContainsString('Tanpa batas waktu', $html);
        $this->assertStringContainsString('No time limit', $html);
    }

    public function test_days_expiry_is_counted_from_successful_send(): void
    {
        $this->workspace->settings = ['csat' => ['expiry_unit' => 'days', 'expiry_value' => 3]];
        $this->workspace->save();

        $template = $this->template();

        $this->asOperation($this->context())
            ->post(route('csat.send.store'), ['template_id' => $template->id])
            ->assertSessionHasNoErrors();

        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', 'TCK-1')->sole();
        (new SendSurveyEmail($invitation->deliveries()->sole()->id))->handle();

        $expiresAt = $invitation->fresh()->expires_at;
        $this->assertNotNull($expiresAt);
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $expiresAt->timestamp, 120);
    }

    public function test_double_click_does_not_create_or_send_another_email(): void
    {
        $template = $this->template();
        $otherTemplate = $this->template(null, ['title' => 'Template Lain']);

        $payload = ['template_id' => $template->id];

        $this->asOperation($this->context())->post(route('csat.send.store'), $payload)->assertSessionHasNoErrors();
        $invitation = Invitation::withoutGlobalScopes()->where('ticket_id', 'TCK-1')->sole();
        (new SendSurveyEmail($invitation->deliveries()->sole()->id))->handle();

        // Template berbeda pada klik berikutnya tidak boleh mengubah tautan yang sudah terkirim.
        $this->asOperation($this->context())
            ->post(route('csat.send.store'), ['template_id' => $otherTemplate->id])
            ->assertRedirect()
            ->assertSessionHas('success', __('CSAT untuk tiket ini sudah dikirim. Tidak ada email baru yang dibuat.'));

        $this->assertSame(1, Invitation::withoutGlobalScopes()->where('ticket_id', 'TCK-1')->count());
        $this->assertSame($template->id, $invitation->fresh()->survey_id);
        $this->assertSame(1, EmailDelivery::where('invitation_id', $invitation->id)->count());
        Mail::assertSent(CsatInvitationMail::class, 1);
    }

    public function test_csat_public_link_skips_code_verification_and_submits(): void
    {
        $template = $this->template();
        $invitation = $this->invitationFor($template);

        $this->get(route('survey.public', $invitation->token))
            ->assertOk()
            ->assertSee('Seberapa puas Anda dengan penanganan tiket ini?')
            ->assertDontSee('Masukkan kode akses');

        $question = $template->questions()->sole();

        $this->post(route('survey.submit', $invitation->token), [
            'action' => 'submit',
            'answers' => [$question->id => ['value' => '5']],
        ])->assertRedirect(route('survey.public', $invitation->token));

        $this->assertSame(1, SurveyResponse::where('invitation_id', $invitation->id)->count());
        $this->assertNotNull($invitation->fresh()->completed_at);
    }

    public function test_expired_csat_link_shows_clear_message_and_rejects_submission(): void
    {
        $template = $this->template();
        $invitation = $this->invitationFor($template, ['expires_at' => now()->subMinute()]);

        $this->get(route('survey.public', $invitation->token))
            ->assertOk()
            ->assertSee('Tautan CSAT sudah kedaluwarsa')
            ->assertDontSee('Seberapa puas Anda dengan penanganan tiket ini?');

        $question = $template->questions()->sole();

        $this->post(route('survey.submit', $invitation->token), [
            'action' => 'submit',
            'answers' => [$question->id => ['value' => '5']],
        ])->assertOk()->assertSee('Tautan CSAT sudah kedaluwarsa');

        $this->assertSame(0, SurveyResponse::where('invitation_id', $invitation->id)->count());
    }

    public function test_csat_notifications_go_to_workspace_operation_and_superadmin_only(): void
    {
        $superadmin = $this->user('superadmin');
        $legacyAdmin = $this->user('admin');

        $otherWorkspace = Workspace::create(['name' => 'Brand B', 'code' => 'brand-b']);
        $otherOperation = $this->user('operation', $otherWorkspace);

        $template = $this->template();
        $invitation = $this->invitationFor($template);
        $question = $template->questions()->sole();

        $response = app(ResponseService::class)->save($invitation, [
            'answers' => [$question->id => ['value' => '2', 'comment' => 'Kurang cepat.']],
        ]);

        Queue::assertPushed(SendSurveyCompletionEmail::class, 2);
        $this->assertSame(2, AdminSurveyNotification::count());
        $this->assertDatabaseHas('admin_survey_notifications', ['recipient_email' => $this->operation->email]);
        $this->assertDatabaseHas('admin_survey_notifications', ['recipient_email' => $superadmin->email]);
        $this->assertDatabaseMissing('admin_survey_notifications', ['recipient_email' => $legacyAdmin->email]);
        $this->assertDatabaseMissing('admin_survey_notifications', ['recipient_email' => $otherOperation->email]);

        foreach (AdminSurveyNotification::all() as $notification) {
            (new SendSurveyCompletionEmail($notification->id))->handle();
        }

        Mail::assertSent(SurveyCompletedMail::class, 2);
        Mail::assertSent(SurveyCompletedMail::class, fn ($mail) => $mail->hasTo($this->operation->email));
        Mail::assertSent(SurveyCompletedMail::class, fn ($mail) => $mail->hasTo($superadmin->email));
        Mail::assertNotSent(SurveyCompletedMail::class, fn ($mail) => $mail->hasTo($legacyAdmin->email));

        $html = (new SurveyCompletedMail($response->load(['invitation', 'client', 'survey']), 'Operation'))->render();
        $this->assertStringContainsString('Respons CSAT sudah diterima', $html);
        $this->assertStringContainsString('TCK-1', $html);
    }

    public function test_csat_invitations_are_hidden_from_client_invitation_list(): void
    {
        $admin = $this->user('admin');
        $legacy = app(CurrentWorkspace::class)->legacyId();

        $clientSurvey = new Survey([
            'title' => 'Survei Client',
            'type' => 'client',
            'status' => 'active',
            'starts_at' => today()->subDay(),
            'ends_at' => today()->addWeek(),
            'created_by' => $admin->id,
        ]);
        $clientSurvey->workspace_id = $legacy;
        $clientSurvey->save();

        $csatSurvey = new Survey([
            'title' => 'Template CSAT Legacy',
            'type' => 'csat',
            'status' => 'active',
            'starts_at' => today(),
            'ends_at' => null,
            'is_template' => true,
            'created_by' => $admin->id,
        ]);
        $csatSurvey->workspace_id = $legacy;
        $csatSurvey->save();

        $client = new Client([
            'name' => 'Klien Legacy',
            'contact' => 'PIC Legacy',
            'email' => 'client-visible@example.test',
            'project' => 'Legacy',
            'active' => true,
        ]);
        $client->workspace_id = $legacy;
        $client->save();

        foreach ([[$clientSurvey, 'client-visible@example.test', ''], [$csatSurvey, 'csat-secret@example.test', 'TCK-LEGACY']] as [$survey, $email, $ticket]) {
            $token = Str::random(64);
            Invitation::create([
                'survey_id' => $survey->id,
                'client_id' => $client->id,
                'ticket_id' => $ticket,
                'token' => $token,
                'token_hash' => hash('sha256', $token),
                'recipient_name' => 'PIC Legacy',
                'recipient_email' => $email,
                'is_demo' => false,
            ]);
        }

        $this->actingAs($admin)
            ->withSession([CurrentWorkspace::SESSION_KEY => $legacy])
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertSee('client-visible@example.test')
            ->assertDontSee('csat-secret@example.test');
    }

    public function test_operation_cannot_send_csat_with_another_workspace_template(): void
    {
        $otherWorkspace = Workspace::create(['name' => 'Brand B', 'code' => 'brand-b']);
        $otherTemplate = $this->template($otherWorkspace);

        $this->asOperation($this->context())
            ->post(route('csat.send.store'), ['template_id' => $otherTemplate->id])
            ->assertSessionHasErrors('template_id');

        $this->assertSame(0, Invitation::withoutGlobalScopes()->where('ticket_id', 'TCK-1')->count());
    }

    public function test_launch_with_ticket_redirects_to_send_csat_page(): void
    {
        $payload = [
            'user' => [
                'id' => 'cx-user-1',
                'name' => 'Operator CXP',
                'email' => 'operator@cxp.test',
            ],
            'project' => [
                'code' => 'brand-cxp',
                'name' => 'Brand CXP',
            ],
            'ticket' => [
                'id' => 'TCK-LAUNCH',
                'status' => 'solved',
                'channel' => 'email',
                'customer' => [
                    'id' => 'CUST-LAUNCH',
                    'name' => 'Sari',
                    'email' => 'sari@example.test',
                ],
            ],
        ];

        $code = (string) $this->postJson('/api/cxp/launch-sessions', $payload, [
            'Authorization' => 'Bearer test-launch-key',
        ])->assertCreated()->json('code');

        $this->get(route('cxp.launch', ['code' => $code]))
            ->assertRedirect(route('csat.send.index'));

        $this->assertSame('TCK-LAUNCH', session('cxp.context.ticket.id'));
        $this->assertSame('sari@example.test', session('cxp.context.customer.email'));
    }
}
