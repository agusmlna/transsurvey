<?php

namespace Tests\Feature;

use App\Jobs\SendSurveyEmail;
use App\Mail\SurveyInvitationMail;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Survey;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\SurveyAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SurveyAccessCodeTest extends TestCase
{
    use RefreshDatabase;

    private function invitation(): Invitation
    {
        $user = User::first() ?? User::create([
            'name' => 'Admin Test', 'email' => 'admin@example.test',
            'password' => 'TestOnly!12345', 'role' => 'admin',
        ]);
        $client = Client::create([
            'name' => 'Client Test', 'contact' => 'PIC Test',
            'email' => 'pic@example.test', 'project' => 'IT', 'active' => true,
        ]);
        $survey = Survey::create([
            'title' => 'Survei verifikasi', 'status' => 'active',
            'starts_at' => today()->subDay(), 'ends_at' => today()->addWeek(),
            'created_by' => $user->id,
        ]);
        $survey->questions()->create([
            'text' => 'Pertanyaan hanya setelah verifikasi', 'category' => 'Layanan',
            'type' => 'text', 'required' => true, 'position' => 1,
        ]);
        $token = Str::random(64);
        return Invitation::create([
            'survey_id' => $survey->id, 'client_id' => $client->id,
            'token' => $token, 'token_hash' => hash('sha256', $token),
            'recipient_name' => $client->contact, 'recipient_email' => $client->email,
        ])->refresh();
    }

    private function send(Invitation $invitation, string $kind = 'invitation'): Invitation
    {
        config(['survey.email_enabled' => true, 'mail.default' => 'smtp']);
        Queue::fake();
        Mail::fake(); // No SMTP connection or external email in tests.
        $delivery = app(DeliveryService::class)->enqueue($invitation, $kind);
        (new SendSurveyEmail($delivery->id))->handle();
        Mail::assertSent(SurveyInvitationMail::class, function ($mail) use ($invitation) {
            return $mail->hasTo($invitation->recipient_email)
                && preg_match('/\A[0-9]{6}\z/', $mail->invitation->access_code) === 1;
        });
        return $invitation->refresh();
    }

    private function payload(Invitation $invitation, string $action = 'submit'): array
    {
        return ['action' => $action, 'answers' => [
            $invitation->survey->questions->first()->id => ['value' => 'Jawaban pengujian'],
        ]];
    }

    public function test_unverified_link_reveals_neither_questions_nor_saved_draft(): void
    {
        $invitation = $this->send($this->invitation());
        $invitation->update(['draft_answers' => ['secret' => ['value' => 'DRAF RAHASIA']]]);
        $response = $this->get($invitation->surveyUrl())->assertOk()->assertViewIs('public.access');
        $response->assertDontSee('Pertanyaan hanya setelah verifikasi')->assertDontSee('DRAF RAHASIA');
        $response->assertDontSee($invitation->access_code);
        $this->assertNull($invitation->fresh()->started_at);
    }

    public function test_legacy_unsent_link_is_locked_until_a_new_email_contains_a_code(): void
    {
        $invitation = $this->invitation();
        $invitation->update(['sent_at' => now()->subDays(2)]);
        $this->get($invitation->surveyUrl())->assertOk()->assertSee('belum tersedia')
            ->assertDontSee('name="access_code"', false);
        $this->send($invitation, 'reminder');
        $this->get($invitation->surveyUrl())->assertOk()->assertSee('name="access_code"', false);
    }

    public function test_unverified_draft_and_final_posts_cannot_bypass_the_gate(): void
    {
        $invitation = $this->send($this->invitation());
        foreach (['draft', 'submit'] as $action) {
            $this->post($invitation->surveyUrl(), $this->payload($invitation, $action) + [
                'access_code' => $invitation->access_code,
            ])->assertRedirect($invitation->surveyUrl())->assertSessionHasErrors('access_code');
        }
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertNull($invitation->fresh()->draft_answers);
        $this->assertNull($invitation->fresh()->started_at);
    }

    public function test_valid_code_opens_survey_and_allows_draft_then_final_only_once(): void
    {
        $invitation = $this->send($this->invitation());
        $this->post(route('survey.verify', $invitation->token), ['access_code' => $invitation->access_code])
            ->assertRedirect($invitation->surveyUrl())->assertSessionHasNoErrors();
        $this->get($invitation->surveyUrl())->assertOk()->assertViewIs('public.survey')
            ->assertSee('Pertanyaan hanya setelah verifikasi')->assertDontSee($invitation->access_code);
        $this->post($invitation->surveyUrl(), $this->payload($invitation, 'draft'))->assertSessionHasNoErrors();
        $this->get($invitation->surveyUrl())->assertSee('Jawaban pengujian');
        $this->post($invitation->surveyUrl(), $this->payload($invitation))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertNull($invitation->fresh()->draft_answers);
        $this->get($invitation->surveyUrl())->assertViewIs('public.thanks');
        $this->post($invitation->surveyUrl(), $this->payload($invitation))->assertSessionHasErrors('survey');
        $this->assertDatabaseCount('survey_responses', 1);
    }

    public function test_another_invitation_code_and_verified_session_do_not_unlock_this_link(): void
    {
        $first = $this->send($this->invitation());
        $second = $this->send($this->invitation());
        $this->assertNotSame($first->access_code, $second->access_code);
        $this->post(route('survey.verify', $first->token), ['access_code' => $first->access_code])
            ->assertSessionHasNoErrors();
        $this->get($second->surveyUrl())->assertViewIs('public.access');
        $this->post(route('survey.verify', $second->token), ['access_code' => $first->access_code])
            ->assertSessionHasErrors('access_code');
        $this->post($second->surveyUrl(), $this->payload($second))->assertSessionHasErrors('access_code');
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_attempt_budget_cannot_be_reset_by_changing_ip_or_browser_session(): void
    {
        $invitation = $this->send($this->invitation());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('survey.verify', $invitation->token), ['access_code' => '000000'])
                ->assertSessionHasErrors('access_code')->assertSessionMissing('_old_input.access_code');
        }
        $this->app['session']->flush();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
            ->post(route('survey.verify', $invitation->token), ['access_code' => $invitation->access_code])
            ->assertStatus(429)->assertHeader('Retry-After')->assertSee('Terlalu banyak percobaan');
        $this->travel(11)->minutes();
        $this->post(route('survey.verify', $invitation->token), ['access_code' => $invitation->access_code])
            ->assertRedirect($invitation->surveyUrl())->assertSessionHasNoErrors();
        $this->get($invitation->surveyUrl())->assertViewIs('public.survey');
    }

    public function test_expired_session_requires_code_again_and_preserves_saved_draft(): void
    {
        $invitation = $this->send($this->invitation());
        $this->post(route('survey.verify', $invitation->token), ['access_code' => $invitation->access_code]);
        $this->post($invitation->surveyUrl(), $this->payload($invitation, 'draft'));
        $this->travel(9)->hours();
        $this->get($invitation->surveyUrl())->assertViewIs('public.access')->assertDontSee('Jawaban pengujian');
        $this->post($invitation->surveyUrl(), $this->payload($invitation))->assertSessionHasErrors('access_code');
        $this->post(route('survey.verify', $invitation->token), ['access_code' => $invitation->access_code]);
        $this->get($invitation->surveyUrl())->assertViewIs('public.survey')->assertSee('Jawaban pengujian');
    }

    public function test_closed_or_completed_surveys_cannot_be_unlocked_even_with_correct_code(): void
    {
        $invitation = $this->send($this->invitation());
        $this->post(route('survey.verify', $invitation->token), ['access_code' => $invitation->access_code]);
        $invitation->survey->update(['status' => 'closed']);
        $this->get($invitation->surveyUrl())->assertViewIs('public.access')
            ->assertDontSee('name="access_code"', false)->assertDontSee('Pertanyaan hanya setelah verifikasi');
        $this->post($invitation->surveyUrl(), $this->payload($invitation))->assertSessionHasErrors('survey');
        $this->post(route('survey.verify', $invitation->token), ['access_code' => $invitation->access_code]);
        $this->assertDatabaseCount('survey_responses', 0);
        $invitation->survey->update(['status' => 'active']);
        $this->get($invitation->surveyUrl())->assertViewIs('public.access');
        $invitation->update(['completed_at' => now()]);
        $this->post(route('survey.verify', $invitation->token), ['access_code' => $invitation->access_code]);
        $this->get($invitation->surveyUrl())->assertViewIs('public.thanks');
    }

    public function test_invitation_and_reminder_email_contain_the_same_code_without_plaintext_database_storage(): void
    {
        $invitation = $this->send($this->invitation());
        $code = $invitation->access_code;
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
        $this->assertTrue(Hash::check($code, $invitation->access_code_hash));
        $stored = DB::table('invitations')->where('id', $invitation->id)->first();
        $this->assertNotSame($code, $stored->access_code);
        foreach (['access_code', 'access_code_hash', 'access_code_fingerprint'] as $key) {
            $this->assertArrayNotHasKey($key, $invitation->toArray());
        }
        $html = (new SurveyInvitationMail($invitation))->render();
        $this->assertStringContainsString($code, $html);
        $this->assertStringNotContainsString($invitation->access_code_hash, $html);
        $this->send($invitation, 'reminder');
        $this->assertSame($code, $invitation->access_code);
        $this->assertStringContainsString($code, (new SurveyInvitationMail($invitation, true))->render());
    }

    public function test_invalid_code_shapes_and_unknown_tokens_are_rejected(): void
    {
        $invitation = $this->send($this->invitation());
        foreach ([['123456'], '12345', '1234567', 'abcdef'] as $code) {
            $this->post(route('survey.verify', $invitation->token), ['access_code' => $code])
                ->assertSessionHasErrors('access_code');
        }
        $this->get('/s/'.str_repeat('z', 64))->assertNotFound();
        $this->post('/s/'.str_repeat('z', 64).'/verify', ['access_code' => '123456'])->assertNotFound();
    }
}
