<?php
namespace Tests\Feature;

use App\Models\{User, Client, Survey, Invitation, SurveyResponse};
use App\Jobs\SendSurveyEmail;
use App\Services\DeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Mail, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class SurveyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::create(['name'=>'Admin Uji','email'=>'admin@example.test','password'=>'TestOnly!12345','role'=>'admin']);
        $client = Client::create(['name'=>'Klien Uji','contact'=>'PIC','email'=>'pic@example.test','project'=>'IT Support','active'=>true]);
        $survey = Survey::create(['title'=>'Survei Uji','status'=>'active','starts_at'=>today()->subDay(),'ends_at'=>today()->addDays(7),'created_by'=>$user->id]);
        $survey->refresh();
        $rating = $survey->questions()->create(['text'=>'Kualitas layanan?','category'=>'Layanan','type'=>'rating','required'=>true,'position'=>1]);
        $second = $survey->questions()->create(['text'=>'Kecepatan?','category'=>'Layanan','type'=>'rating','required'=>true,'position'=>2]);
        $choice = $survey->questions()->create(['text'=>'Kanal?','category'=>'Preferensi','type'=>'choice','options'=>['Email','Telepon'],'required'=>false,'position'=>3]);
        $token = Str::random(64);
        $invitation = Invitation::create(['survey_id'=>$survey->id,'client_id'=>$client->id,'token'=>$token,'token_hash'=>hash('sha256',$token),'recipient_name'=>$client->contact,'recipient_email'=>$client->email]);
        return compact('user','client','survey','rating','second','choice','token','invitation');
    }

    private function payload(array $f, string $score='3', string $comment='Mohon lebih cepat.'): array
    {
        return ['action'=>'submit','answers'=>[
            $f['rating']->id=>['value'=>$score,'comment'=>$comment],
            $f['second']->id=>['value'=>'5'],
        ]];
    }

    public function test_admin_pages_render_and_guests_are_redirected(): void
    {
        $f=$this->fixture();
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->actingAs($f['user']);
        foreach (['/','/surveys','/surveys/create','/surveys/'.$f['survey']->id.'/edit','/surveys/'.$f['survey']->id.'/preview','/clients','/clients/create','/clients/'.$f['client']->id.'/edit','/invitations','/responses','/bank','/reports','/followups'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_low_scores_require_a_nonblank_comment(): void
    {
        $f=$this->fixture();
        foreach (['1','2','3'] as $score) {
            $this->post('/s/'.$f['token'],$this->payload($f,$score,'   '))->assertSessionHasErrors('answers.'.$f['rating']->id.'.comment');
        }
        $this->assertDatabaseCount('survey_responses',0);
        $this->assertDatabaseCount('follow_ups',0);
    }

    public function test_score_followup_and_duplicate_submission(): void
    {
        $f=$this->fixture();
        $this->post('/s/'.$f['token'],$this->payload($f))->assertSessionHasNoErrors()->assertRedirect('/s/'.$f['token']);
        $this->assertDatabaseHas('survey_responses',['invitation_id'=>$f['invitation']->id,'score'=>4.0]);
        $this->assertDatabaseCount('follow_ups',1);
        $this->assertNotNull($f['invitation']->fresh()->completed_at);
        $this->post('/s/'.$f['token'],$this->payload($f))->assertSessionHasErrors('survey');
        $this->assertDatabaseCount('survey_responses',1);
        $this->actingAs($f['user'])->get('/responses/'.SurveyResponse::first()->id)->assertOk();
        $this->get('/followups/'.SurveyResponse::first()->followUp->id.'/edit')->assertOk();
    }

    public function test_high_rating_needs_no_comment_and_no_followup(): void
    {
        $f=$this->fixture();
        $this->post('/s/'.$f['token'],$this->payload($f,'4',''))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('survey_responses',['score'=>4.5]);
        $this->assertDatabaseCount('follow_ups',0);
    }

    public function test_draft_can_be_resumed_then_submitted(): void
    {
        $f=$this->fixture();
        $this->post('/s/'.$f['token'],['action'=>'draft','answers'=>[$f['rating']->id=>['value'=>'2']]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('survey_responses',0);
        $this->assertNotNull($f['invitation']->fresh()->started_at);
        $this->assertSame('2',$f['invitation']->fresh()->draft_answers[$f['rating']->id]['value']);
        $this->get('/s/'.$f['token'])->assertOk();
        $this->post('/s/'.$f['token'],$this->payload($f))->assertSessionHasNoErrors();
        $this->assertNull($f['invitation']->fresh()->draft_answers);
    }

    public function test_closed_survey_and_invalid_choice_are_rejected(): void
    {
        $f=$this->fixture();$data=$this->payload($f);
        $data['answers'][$f['choice']->id]=['value'=>'Forged option'];
        $this->post('/s/'.$f['token'],$data)->assertSessionHasErrors('answers.'.$f['choice']->id.'.value');
        $f['survey']->update(['status'=>'closed']);
        $this->post('/s/'.$f['token'],$this->payload($f))->assertSessionHasErrors('survey');
        $this->assertDatabaseCount('survey_responses',0);
        $this->get('/s/'.str_repeat('z',64))->assertNotFound();
    }

    public function test_viewer_cannot_modify_surveys_or_send_email(): void
    {
        $f=$this->fixture();$f['user']->update(['role'=>'viewer']);
        $this->actingAs($f['user'])->get('/surveys/create')->assertForbidden();
        $this->post('/invitations/'.$f['invitation']->id.'/send')->assertForbidden();
        $this->get('/reports')->assertOk();
    }

    public function test_invitation_creation_is_idempotent_for_same_survey_client(): void
    {
        $f=$this->fixture();
        $this->actingAs($f['user'])->post('/invitations',['survey_id'=>$f['survey']->id,'client_ids'=>[$f['client']->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('invitations',1);
    }

    public function test_email_disabled_and_demo_email_are_blocked(): void
    {
        $f=$this->fixture();Queue::fake();
        $this->actingAs($f['user'])->post('/invitations/'.$f['invitation']->id.'/send')->assertSessionHasErrors('mail');
        config(['survey.email_enabled'=>true,'mail.default'=>'smtp']);
        $f['invitation']->update(['is_demo'=>true]);
        $this->post('/invitations/'.$f['invitation']->id.'/send')->assertSessionHasErrors('mail');
        Queue::assertNothingPushed();
    }

    public function test_email_job_sends_once_and_updates_delivery_state(): void
    {
        $f=$this->fixture();Queue::fake();Mail::fake();
        config(['survey.email_enabled'=>true,'mail.default'=>'smtp']);
        $delivery=app(DeliveryService::class)->enqueue($f['invitation'],'invitation');
        Queue::assertPushed(SendSurveyEmail::class);
        $job=new SendSurveyEmail($delivery->id);$job->handle();$job->handle();
        Mail::assertSentCount(1);
        $this->assertSame('sent',$delivery->fresh()->status);
        $this->assertNotNull($f['invitation']->fresh()->reminder_at);
    }

    public function test_report_filters_and_formula_safe_csv(): void
    {
        $f=$this->fixture();$f['client']->update(['name'=>'=HYPERLINK("https://example.test")']);
        $this->post('/s/'.$f['token'],$this->payload($f))->assertSessionHasNoErrors();
        $this->actingAs($f['user']);
        $csv=$this->get('/reports/export.csv?client_id='.$f['client']->id)->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK",$csv);
        $this->get('/reports?from=not-a-date')->assertSessionHasErrors('from');
        $this->get('/reports?source=demo')->assertOk()->assertSee('0 respons tercatat');
    }

    public function test_followup_resolution_requires_notes_and_checks_edit_version(): void
    {
        $f=$this->fixture();
        $this->post('/s/'.$f['token'],$this->payload($f));
        $followup=SurveyResponse::first()->followUp;
        $data=['assigned_to'=>$f['user']->id,'due_at'=>today()->addDay()->format('Y-m-d'),'status'=>'resolved','version'=>$followup->version];
        $this->actingAs($f['user'])->put('/followups/'.$followup->id,$data)->assertSessionHasErrors('notes');
        $data['notes']='Sudah dievaluasi bersama PIC.';
        $this->put('/followups/'.$followup->id,$data)->assertSessionHasNoErrors();
        $this->assertNotNull($followup->fresh()->resolved_at);
        $this->put('/followups/'.$followup->id,$data)->assertSessionHasErrors('followup');
    }

    public function test_distributed_questions_are_locked_and_survey_can_be_duplicated(): void
    {
        $f=$this->fixture();
        $data=['title'=>'Judul diperbarui','description'=>'','status'=>'active','starts_at'=>today()->format('Y-m-d'),'ends_at'=>today()->addWeek()->format('Y-m-d'),'version'=>$f['survey']->version,'questions'=>[['text'=>'Tidak boleh menimpa','category'=>'Baru','type'=>'text','required'=>true]]];
        $this->actingAs($f['user'])->put('/surveys/'.$f['survey']->id,$data)->assertSessionHasNoErrors();
        $this->assertSame('Kualitas layanan?',$f['rating']->fresh()->text);
        $this->put('/surveys/'.$f['survey']->id,$data)->assertSessionHasErrors('survey');
        $this->post('/surveys/'.$f['survey']->id.'/duplicate')->assertRedirect();
        $copy=Survey::latest('id')->first();
        $this->assertSame('draft',$copy->status);
        $this->assertSame(3,$copy->questions()->count());
        $this->assertSame(0,$copy->invitations()->count());
    }
}
