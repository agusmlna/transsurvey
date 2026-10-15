<?php

namespace Tests\Feature;

use App\Jobs\{SendSurveyEmail, SendSurveyCompletionEmail};
use App\Models\{User, Client, Survey, Invitation, SurveyResponse, EmailDelivery, AdminSurveyNotification, FollowUp, QuestionImport};
use App\Services\DataDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

class DataDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::create(['name'=>'Admin Testing', 'email'=>Str::random(12).'@example.test', 'password'=>'TestOnly!12345', 'role'=>'admin']);
        $client = Client::create(['name'=>'Client Test', 'contact'=>'PIC', 'email'=>'pic@example.test', 'project'=>'CCS', 'active'=>true]);
        $survey = Survey::create(['title'=>'Survey Test', 'status'=>'active', 'starts_at'=>today()->subDay(), 'ends_at'=>today()->addDays(7), 'created_by'=>$admin->id]);
        $question = $survey->questions()->create(['text'=>'Service?', 'category'=>'Service', 'type'=>'rating', 'required'=>true, 'position'=>1]);
        $token = Str::random(64);
        $invitation = Invitation::create(['survey_id'=>$survey->id, 'client_id'=>$client->id, 'token'=>$token, 'token_hash'=>hash('sha256',$token), 'recipient_email'=>$client->email, 'recipient_name'=>$client->contact, 'completed_at'=>now()]);
        $response = SurveyResponse::create(['invitation_id'=>$invitation->id, 'survey_id'=>$survey->id, 'client_id'=>$client->id, 'score'=>2, 'submitted_at'=>now()]);
        $response->answers()->create(['question_id'=>$question->id,'question_text'=>'Service?', 'category'=>'Service', 'type'=>'rating', 'value'=>'2','comment'=>'Private comment']);
        FollowUp::create(['survey_response_id'=>$response->id,'status'=>'open','due_at'=>today()->addDays(3)]);
        $delivery = EmailDelivery::create(['invitation_id'=>$invitation->id,'kind'=>'invitation','dedupe_key'=>'invitation:'.$invitation->id,'status'=>'pending']);
        $notification = AdminSurveyNotification::create(['survey_response_id'=>$response->id,'user_id'=>$admin->id,'recipient_email'=>$admin->email,'status'=>'pending']);
        return compact('admin','client','survey','question','invitation','response','delivery','notification','token');
    }

    private function preview(User $admin, string $type, array $ids): array
    {
        $this->actingAs($admin)->post(route('data-cleanup.preview'), ['type'=>$type, 'ids'=>$ids, 'reason'=>'Remove test records'])->assertSessionHasNoErrors()->assertRedirect();
        return session('data_cleanup_preview');
    }
    private function confirm(array $preview)
    {
        return $this->post(route('data-cleanup.destroy', $preview['token']), ['confirmation'=>'HAPUS','acknowledge'=>'1']);
    }

    public function test_guests_and_viewers_cannot_read_preview_delete_or_audit(): void
    {
        $f=$this->fixture();
        $this->get('/data-cleanup')->assertRedirect('/login');
        $f['admin']->update(['role'=>'viewer']); $this->actingAs($f['admin']);
        $this->get('/data-cleanup')->assertForbidden();
        $this->get('/data-cleanup/history')->assertForbidden();
        $this->post('/data-cleanup/preview', ['type'=>'invitations','ids'=>[$f['invitation']->id],'reason'=>'Test data'])->assertForbidden();
        $this->post('/data-cleanup/delete/'.Str::random(64), ['confirmation'=>'HAPUS','acknowledge'=>1])->assertForbidden();
        $this->assertDatabaseCount('invitations',1);
    }

    public function test_csrf_is_required_for_deletion(): void
    {
        $f=$this->fixture();$p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        $this->app['env']='local';
        $this->confirm($p)->assertStatus(419);
        $this->assertDatabaseCount('invitations',1);
    }

    public function test_preview_counts_all_related_records_without_deleting_and_escapes_labels(): void
    {
        $f=$this->fixture(); $f['client']->update(['name'=>'<script>alert(1)</script>']);
        $p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        foreach (['invitations','responses','answers','followups','deliveries','notifications'] as $key) $this->assertSame(1,$p['counts'][$key]);
        $this->assertSame(0,$p['counts']['clients']);
        $this->get(route('data-cleanup.show',$p['token']))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;',false)->assertDontSee('<script>alert(1)</script>',false);
        $this->assertDatabaseCount('invitations',1);
        $this->assertStringNotContainsString('Private comment',json_encode($p));
        $this->assertStringNotContainsString($f['token'],json_encode($p));
    }

    public function test_exact_confirmation_and_acknowledgement_are_required(): void
    {
        $f=$this->fixture();$p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        $this->post(route('data-cleanup.destroy',$p['token']),['confirmation'=>'hapus','acknowledge'=>1])->assertSessionHasErrors('confirmation');
        $this->post(route('data-cleanup.destroy',$p['token']),['confirmation'=>'HAPUS'])->assertSessionHasErrors('acknowledge');
        $this->assertDatabaseCount('invitations',1);
    }

    public function test_bulk_delete_removes_only_selected_trees_and_records_audit(): void
    {
        $a=$this->fixture();$b=$this->fixture();$keep=$this->fixture();
        $p=$this->preview($a['admin'],'invitations',[$a['invitation']->id,$b['invitation']->id]);
        $this->confirm($p)->assertSessionHasNoErrors()->assertRedirect(route('data-cleanup.index',['type'=>'invitations']));
        foreach (['invitations','survey_responses','answers','follow_ups','email_deliveries','admin_survey_notifications'] as $table) $this->assertDatabaseCount($table,1);
        $this->assertDatabaseHas('invitations',['id'=>$keep['invitation']->id]);
        $this->assertDatabaseCount('clients',3);$this->assertDatabaseCount('surveys',3);
        $this->assertDatabaseHas('data_deletion_audits',['user_id'=>$a['admin']->id,'reason'=>'Remove test records','entity'=>'invitations']);
        $audit=DB::table('data_deletion_audits')->first();
        $this->assertSame(2,json_decode($audit->summary,true)['responses']);
        $this->assertStringNotContainsString('Private comment',json_encode($audit));
        $this->get('/data-cleanup/history')->assertOk()->assertSee('Remove test records');
        $summary = app(\App\Services\ReportService::class)->summary(\Illuminate\Http\Request::create('/reports'));
        $this->assertSame(1, $summary['invCount']);
        $this->assertSame(1, $summary['complete']);
        $this->assertSame(1, $summary['responses']->count());
        $this->assertSame(1, $summary['openFollow']);
        $this->assertEquals(2, $summary['average']);
    }

    public function test_response_cleanup_revokes_invitation_and_old_public_link(): void
    {
        $f=$this->fixture();$p=$this->preview($f['admin'],'responses',[$f['response']->id]);
        $this->confirm($p)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('survey_responses',0);$this->assertDatabaseCount('invitations',0);
        $this->get('/s/'.$f['token'])->assertNotFound();
        $this->post('/s/'.$f['token'],['action'=>'submit'])->assertNotFound();
    }

    public function test_stale_preview_rejects_new_answer_or_notification_changes(): void
    {
        $f=$this->fixture();$p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        $f['notification']->update(['status'=>'sent','sent_at'=>now()]);
        $this->confirm($p)->assertSessionHasErrors('cleanup');
        $this->assertDatabaseCount('invitations',1);$this->assertDatabaseCount('data_deletion_audits',0);
    }

    public function test_new_submission_after_preview_requires_another_review(): void
    {
        $f=$this->fixture(); DB::table('admin_survey_notifications')->delete(); DB::table('follow_ups')->delete();
        DB::table('answers')->delete(); DB::table('survey_responses')->delete();$f['invitation']->update(['completed_at'=>null]);
        $p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        SurveyResponse::create(['invitation_id'=>$f['invitation']->id,'client_id'=>$f['client']->id,'survey_id'=>$f['survey']->id,'score'=>5,'submitted_at'=>now()]);
        $this->confirm($p)->assertSessionHasErrors('cleanup');$this->assertDatabaseCount('survey_responses',1);
    }

    public function test_sending_admin_email_blocks_preview_and_confirmation(): void
    {
        $f=$this->fixture();$p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        $f['notification']->update(['status'=>'sending']);
        $this->confirm($p)->assertSessionHasErrors('cleanup');
        $this->post('/data-cleanup/preview',['type'=>'invitations','ids'=>[$f['invitation']->id],'reason'=>'Test data'])->assertSessionHasErrors('cleanup');
        $this->assertDatabaseCount('invitations',1);
    }

    public function test_old_queued_jobs_are_no_ops_after_cleanup_even_if_smtp_is_disabled(): void
    {
        $f=$this->fixture();Mail::fake();$p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        $this->confirm($p)->assertSessionHasNoErrors();
        foreach ([false,true] as $enabled) {
            config(['survey.email_enabled'=>$enabled,'mail.default'=>'smtp']);
            (new SendSurveyEmail($f['delivery']->id))->handle();
            (new SendSurveyCompletionEmail($f['notification']->id))->handle();
        }
        Mail::assertNothingSent();$this->assertDatabaseCount('invitations',0);
    }

    public function test_client_in_use_and_active_or_distributed_survey_cannot_be_deleted(): void
    {
        $f=$this->fixture();$this->actingAs($f['admin']);
        foreach (['clients'=>$f['client']->id,'surveys'=>$f['survey']->id] as $type=>$id) {
            $this->post('/data-cleanup/preview',['type'=>$type,'ids'=>[$id],'reason'=>'Test data'])->assertSessionHasErrors('cleanup');
        }
        $f['survey']->update(['status'=>'draft']);
        $this->post('/data-cleanup/preview',['type'=>'surveys','ids'=>[$f['survey']->id],'reason'=>'Test data'])->assertSessionHasErrors('cleanup');
        $this->assertDatabaseCount('clients',1);$this->assertDatabaseCount('surveys',1);
    }

    public function test_unused_client_can_be_deleted_without_deleting_others(): void
    {
        $f=$this->fixture();$c=Client::create(['name'=>'Unused','contact'=>'PIC','email'=>'unused@example.test','project'=>'Test','active'=>false]);
        $p=$this->preview($f['admin'],'clients',[$c->id]);$this->confirm($p)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('clients',['id'=>$c->id]);$this->assertDatabaseHas('clients',['id'=>$f['client']->id]);
    }

    public function test_unused_draft_deletes_questions_and_import_previews(): void
    {
        $f=$this->fixture();$s=Survey::create(['title'=>'Draft','status'=>'draft','starts_at'=>today(),'ends_at'=>today()->addWeek(),'created_by'=>$f['admin']->id]);
        $s->questions()->create(['text'=>'Draft question','category'=>'Test','type'=>'text','position'=>1]);
        QuestionImport::create(['token'=>Str::random(64),'survey_id'=>$s->id,'user_id'=>$f['admin']->id,'payload'=>['rows'=>[]],'question_count'=>1,'expires_at'=>now()->addMinutes(30)]);
        $p=$this->preview($f['admin'],'surveys',[$s->id]);
        $this->assertSame(1,$p['counts']['questions']);$this->assertSame(1,$p['counts']['imports']);
        $this->confirm($p)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('surveys',['id'=>$s->id]);$this->assertDatabaseCount('question_imports',0);$this->assertDatabaseCount('questions',1);
    }

    public function test_expired_and_cross_user_previews_are_rejected(): void
    {
        $f=$this->fixture();$other=$this->fixture();$p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        $this->actingAs($other['admin']);$this->confirm($p)->assertNotFound();
        $this->actingAs($f['admin']);$this->travel(11)->minutes();
        $this->confirm($p)->assertSessionHasErrors('cleanup');$this->assertDatabaseCount('invitations',2);
    }

    public function test_forged_ids_on_confirmation_do_not_expand_selection(): void
    {
        $f=$this->fixture();$keep=$this->fixture();$p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        $this->post(route('data-cleanup.destroy',$p['token']),['confirmation'=>'HAPUS','acknowledge'=>1,'ids'=>[$keep['invitation']->id],'type'=>'clients'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('invitations',['id'=>$keep['invitation']->id]);$this->assertDatabaseMissing('invitations',['id'=>$f['invitation']->id]);
    }

    public function test_invalid_selections_and_mixed_in_use_clients_are_atomic(): void
    {
        $f=$this->fixture();$this->actingAs($f['admin']);
        foreach ([[],[$f['invitation']->id,$f['invitation']->id],[$f['invitation']->id,999999],range(1,101)] as $ids) {
            $this->post('/data-cleanup/preview',['type'=>'invitations','ids'=>$ids,'reason'=>'Test data'])->assertSessionHasErrors();
        }
        $c=Client::create(['name'=>'Unused','contact'=>'PIC','email'=>'unused@example.test','project'=>'Test']);
        $this->post('/data-cleanup/preview',['type'=>'clients','ids'=>[$c->id,$f['client']->id],'reason'=>'Test data'])->assertSessionHasErrors('cleanup');
        $this->assertDatabaseCount('clients',2);$this->assertDatabaseCount('invitations',1);
    }

    public function test_failed_audit_insert_rolls_back_deletion(): void
    {
        $f=$this->fixture();$p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        DB::connection()->beforeExecuting(function ($query) {
            if (str_starts_with($query,'insert into "data_deletion_audits"')) throw new \RuntimeException('Simulated audit failure');
        });
        try { app(DataDeletionService::class)->delete($p,$f['admin']);$this->fail('Expected failure'); }
        catch (\RuntimeException $e) { $this->assertSame('Simulated audit failure',$e->getMessage()); }
        $this->assertDatabaseCount('invitations',1);$this->assertDatabaseCount('survey_responses',1);$this->assertDatabaseCount('answers',1);$this->assertDatabaseCount('follow_ups',1);
    }

    public function test_double_confirmation_does_not_duplicate_audit(): void
    {
        $f=$this->fixture();$p=$this->preview($f['admin'],'invitations',[$f['invitation']->id]);
        app(DataDeletionService::class)->delete($p,$f['admin']);app(DataDeletionService::class)->delete($p,$f['admin']);
        $this->assertDatabaseCount('data_deletion_audits',1);
    }

    public function test_lists_render_in_both_languages_and_default_to_five_rows(): void
    {
        $f=$this->fixture();$this->actingAs($f['admin']);
        for($i=0;$i<6;$i++) Client::create(['name'=>'Test '.$i,'contact'=>'PIC','email'=>'test@example.test','project'=>'Test']);
        foreach (['id','en'] as $locale) {
            $this->withSession(['locale'=>$locale]);
            foreach (DataDeletionService::TYPES as $type) $this->get('/data-cleanup?type='.$type)->assertOk();
        }
        $this->get('/data-cleanup?type=clients')->assertViewHas('rows',fn($rows)=>$rows->count()===5 && $rows->total()===7);
        $this->get('/data-cleanup?type=clients&q=Test%205')->assertViewHas('rows',fn($rows)=>$rows->total()===1);
    }
}
