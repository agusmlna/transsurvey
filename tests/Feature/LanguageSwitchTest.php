<?php

namespace Tests\Feature;

use App\Models\{User, Client, Survey, Invitation, SurveyResponse, FollowUp, BankQuestion};
use App\Services\{SurveyAccessService, ReportService, ReportPresentation, ReportExcelExporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Mail, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class LanguageSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Mail::fake(); Queue::fake();
        $user = User::create(['name' => 'Admin Uji', 'email' => 'locale@example.test', 'password' => 'TestOnly!12345', 'role' => 'admin']);
        $client = Client::create(['name' => 'Klien Tetap', 'contact' => 'PIC Uji', 'email' => 'pic@example.test', 'project' => 'Proyek Tetap', 'active' => true]);
        $survey = Survey::create(['title' => 'Judul Tidak Diubah', 'description' => 'Pengantar asli', 'status' => 'active', 'starts_at' => today()->subDay(), 'ends_at' => today()->addWeek(), 'created_by' => $user->id]);
        $question = $survey->questions()->create(['text' => 'Pertanyaan asli klien?', 'category' => 'Kategori Asli', 'type' => 'rating', 'required' => true, 'position' => 1]);
        $token = Str::random(64);
        $invitation = Invitation::create(['survey_id' => $survey->id, 'client_id' => $client->id, 'token' => $token, 'token_hash' => hash('sha256', $token), 'recipient_name' => $client->contact, 'recipient_email' => $client->email]);
        app(SurveyAccessService::class)->prepareForEmail($invitation);
        $response = SurveyResponse::create(['survey_id' => $survey->id, 'client_id' => $client->id, 'invitation_id' => $invitation->id, 'score' => 2, 'submitted_at' => now()]);
        $response->answers()->create(['question_id' => $question->id, 'question_text' => $question->text, 'category' => $question->category, 'type' => 'rating', 'value' => '2', 'comment' => 'Komentar asli']);
        $followup = FollowUp::create(['survey_response_id' => $response->id, 'assigned_to' => $user->id, 'status' => 'open', 'due_at' => today()->addDay()]);
        BankQuestion::create(['text' => 'Pertanyaan bank asli?', 'category' => 'Kategori Asli', 'type' => 'choice', 'required' => true, 'options' => ['Ya asli', 'Tidak asli']]);
        return compact('user', 'client', 'survey', 'question', 'invitation', 'response', 'followup');
    }

    private function visible(string $html): string
    {
        return html_entity_decode(strip_tags(preg_replace('~<(script|style)\b[^>]*>.*?</\1>~s', '', $html)), ENT_QUOTES | ENT_HTML5);
    }

    public function test_default_is_indonesian_and_guest_can_switch_and_keep_query_filters(): void
    {
        $this->get('/login')->assertOk()->assertSee('lang="id"', false)->assertSee('SELAMAT DATANG KEMBALI');
        $this->post('/language', ['locale' => 'en', 'return_to' => '/login?example=1'])
            ->assertStatus(303)->assertRedirect('/login?example=1')->assertSessionHas('locale', 'en')
            ->assertCookie('transsurvey_locale', 'en');
        $response = $this->get('/login')->assertOk()->assertSee('lang="en"', false);
        $this->assertStringContainsString('WELCOME BACK', $this->visible($response->getContent()));
        $this->assertStringNotContainsString('SELAMAT DATANG KEMBALI', $this->visible($response->getContent()));
        $this->post('/language', ['locale' => 'id', 'return_to' => '/login'])->assertSessionHas('locale', 'id');
        $this->get('/login')->assertSee('lang="id"', false);
    }

    public function test_cookie_restores_language_after_session_ends_and_bad_locale_falls_back(): void
    {
        $this->withCookie('transsurvey_locale', 'en')->get('/login')->assertSee('lang="en"', false);
        $this->withSession(['locale' => '../../etc/passwd'])->get('/login')->assertSee('lang="id"', false);
    }

    public function test_invalid_language_and_external_redirects_are_rejected(): void
    {
        $this->postJson('/language', ['locale' => 'fr'])->assertUnprocessable();
        foreach (['https://example.test', '//example.test', '/\\example.test', '/%2fexample.test', "/\nexample.test"] as $path) {
            $this->post('/language', ['locale' => 'en', 'return_to' => $path])->assertRedirect('/');
        }
        $this->post('/language', ['locale' => 'en', 'return_to' => '/reports?from=2026-01-01&source=real'])
            ->assertRedirect('/reports?from=2026-01-01&source=real');
        $this->post('/language', ['locale' => 'en', 'return_to' => '/reports?project=Customer%20Care'])->assertRedirect('/reports?project=Customer%20Care');
        $this->get('/language')->assertStatus(405);
    }

    public function test_switch_requires_csrf_outside_test_bypass(): void
    {
        $this->app['env'] = 'staging';
        $this->post('/language', ['locale' => 'en', 'return_to' => '/login'])->assertStatus(419);
    }

    public function test_admin_pages_and_ajax_rows_translate_without_changing_stored_content(): void
    {
        $f = $this->fixture(); $this->actingAs($f['user'])->withSession(['locale' => 'en']);
        foreach ([
            '/' => 'Track survey results', '/clients' => 'Client list', '/clients/create' => 'Add client',
            '/clients/'.$f['client']->id.'/edit' => 'Edit client', '/surveys' => 'Surveys',
            '/surveys/create' => 'Create a questionnaire', '/surveys/'.$f['survey']->id.'/edit' => 'Edit questionnaire',
            '/invitations' => 'Invitations & reminders', '/responses' => 'Responses', '/bank' => 'Question bank',
            '/reports' => 'Client satisfaction report', '/followups' => 'Follow-up',
            '/followups/'.$f['followup']->id.'/edit' => 'Resolution notes',
            '/responses/'.$f['response']->id => 'Komentar asli',
        ] as $path => $expected) {
            $result = $this->get($path)->assertOk();
            $result->assertSee('lang="en"', false);
            $this->assertStringContainsString($expected, $this->visible($result->getContent()), $path);
        }
        foreach (['clients' => 'Active', 'surveys' => 'Active', 'invitations' => 'Send email', 'bank' => 'Multiple choice', 'followups' => 'Open', 'responses' => 'View low ratings'] as $table => $expected) {
            $result = $this->getJson('/'.$table.'?'.http_build_query(['_table' => 1, 'draw' => 1, 'start' => 0, 'length' => 5]))->assertOk();
            $this->assertStringContainsString($expected, $this->visible($result->json('html')), $table);
        }
        $this->assertSame('Judul Tidak Diubah', $f['survey']->fresh()->title);
        $this->assertSame('Pertanyaan asli klien?', $f['question']->fresh()->text);
        $this->assertSame('active', $f['survey']->fresh()->status);
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function test_public_access_verified_survey_and_thanks_use_selected_language(): void
    {
        $f = $this->fixture(); $inv = $f['invitation']->refresh(); $url = '/s/'.$inv->token;
        $this->withSession(['locale' => 'en'])->get($url)->assertOk()->assertSee('Enter your access code');
        $this->post($url.'/verify', ['access_code' => 'invalid'])->assertSessionHasErrors('access_code');
        $this->get($url)->assertSee('Incorrect code.');
        $this->post($url.'/verify', ['access_code' => $inv->access_code])->assertRedirect($url);
        $r = $this->get($url)->assertOk();
        $this->assertStringContainsString('Very dissatisfied', $this->visible($r->getContent()));
        $this->assertStringContainsString('Pertanyaan asli klien?', $this->visible($r->getContent()));
        $this->post('/language', ['locale' => 'id', 'return_to' => $url])->assertRedirect($url);
        $this->get($url)->assertSee('Sangat tidak puas')->assertDontSee('Masukkan kode akses</h1>', false);
        $inv->update(['completed_at' => now()]);
        $this->withSession(['locale' => 'en'])->get($url)->assertSee('Thank you for your feedback.')->assertSee('data-language-switch', false);
    }

    public function test_validation_and_login_logout_messages_use_locale_and_roles_stay_enforced(): void
    {
        $f = $this->fixture();
        $this->withSession(['locale' => 'en'])->postJson('/login', [])->assertUnprocessable()->assertJsonPath('errors.email.0', 'The email field is required.');
        $this->post('/login', ['email' => $f['user']->email, 'password' => 'TestOnly!12345'])->assertRedirect('/')->assertSessionHas('success', 'Login successful. Welcome to TransSurvey.');
        $this->postJson('/clients', [])->assertUnprocessable()->assertJsonPath('errors.name.0', 'The company name field is required.');
        $this->withSession(['locale' => 'id'])->postJson('/clients', [])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Nama perusahaan wajib diisi.');
        $f['user']->update(['role' => 'viewer']);
        $this->actingAs($f['user']->fresh());
        $this->withSession(['locale' => 'en'])->get('/clients')->assertForbidden();
        $this->post('/logout')->assertRedirect('/login')->assertSessionHas('success', 'You have logged out of TransSurvey.');
    }

    public function test_reports_export_translated_labels_and_preserve_answers(): void
    {
        $f = $this->fixture(); $this->actingAs($f['user'])->withSession(['locale' => 'en']);
        $this->get('/reports')->assertOk();
        $request = Request::create('/reports');
        $data = app(ReportPresentation::class)->data($request, app(ReportService::class));
        $this->assertStringContainsString('Responses matching the selected filters', $data['summaryText']);
        $book = app(ReportExcelExporter::class)->workbook($data);
        try {
            $this->assertSame(['Summary', 'Answer Details'], $book->getSheetNames());
            $flat = json_encode($book->getSheet(1)->toArray());
            $this->assertStringContainsString('Pertanyaan asli klien?', $flat);
            $this->assertStringContainsString('Komentar asli', $flat);
        } finally { $book->disconnectWorksheets(); }
        $this->get('/reports/export.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/reports/export.xlsx')->assertOk()->assertDownload();
    }
}
