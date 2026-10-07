<?php
namespace Tests\Feature;

use App\Models\{User, Client, Survey, Invitation, SurveyResponse, BankQuestion, FollowUp};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Mail, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class DataTablesTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User
    {
        return User::create(['name'=>'Admin Uji', 'email'=>'admin-table@example.test', 'password'=>'TestOnly!12345', 'role'=>'admin']);
    }
    private function table(string $path, array $params = [])
    {
        return $this->getJson($path.'?'.http_build_query(array_replace_recursive([
            '_table'=>1, 'draw'=>3, 'start'=>0, 'length'=>10, 'search'=>['value'=>''],
        ], $params)));
    }
    private function fixture(User $user, string $suffix = 'A', float $score = 2): array
    {
        $client = Client::create(['name'=>'Klien '.$suffix, 'contact'=>'PIC '.$suffix, 'email'=>strtolower($suffix).'@example.test', 'project'=>'Proyek '.$suffix, 'active'=>true]);
        $survey = Survey::create(['title'=>'Survei '.$suffix, 'status'=>'active', 'starts_at'=>today()->subDay(), 'ends_at'=>today()->addWeek(), 'created_by'=>$user->id]);
        $token = Str::random(64);
        $invitation = Invitation::create(['survey_id'=>$survey->id, 'client_id'=>$client->id, 'token'=>$token, 'token_hash'=>hash('sha256',$token), 'recipient_name'=>$client->contact, 'recipient_email'=>$client->email, 'completed_at'=>now()]);
        $response = SurveyResponse::create(['survey_id'=>$survey->id, 'client_id'=>$client->id, 'invitation_id'=>$invitation->id, 'score'=>$score, 'submitted_at'=>now()]);
        $followup = FollowUp::create(['survey_response_id'=>$response->id, 'assigned_to'=>$user->id, 'status'=>'open', 'due_at'=>today()->addDay()]);
        return compact('client','survey','invitation','response','followup');
    }
    public function test_client_search_reaches_past_first_page_and_counts_match(): void
    {
        $this->actingAs($this->admin());
        for ($i=1; $i<=35; $i++) Client::create(['name'=>sprintf('Klien %02d',$i), 'contact'=>'PIC', 'email'=>"pic$i@example.test", 'project'=>'IT', 'active'=>true]);
        $this->table('/clients')->assertOk()->assertJsonPath('draw',3)->assertJsonPath('recordsTotal',35)->assertJsonPath('recordsFiltered',35);
        $result=$this->table('/clients',['search'=>['value'=>'pic35@example.test']])->assertOk()->assertJsonPath('recordsFiltered',1)->json();
        $this->assertStringContainsString('Klien 35',$result['html']);
        $page=$this->table('/clients',['start'=>10,'order'=>[['column'=>0,'dir'=>'asc']]])->assertOk()->json();
        $this->assertStringContainsString('Klien 11',$page['html']);
        $this->assertStringNotContainsString('Klien 01',$page['html']);
        $this->assertSame(10,substr_count($page['html'],'<tr>'));
        $this->table('/clients',['search'=>['value'=>'not-present']])->assertOk()->assertJsonPath('recordsFiltered',0);
    }
    public function test_pages_and_every_ajax_table_render_with_existing_actions(): void
    {
        Mail::fake(); Queue::fake();
        $user=$this->admin(); $f=$this->fixture($user);
        BankQuestion::create(['text'=>'Bagaimana layanan?', 'category'=>'Layanan', 'type'=>'rating', 'required'=>true]);
        $this->actingAs($user);
        foreach (['/clients','/surveys','/invitations','/responses','/bank','/followups'] as $path) {
            $this->get($path)->assertOk()->assertSee('data-server-table',false);
            $this->table($path)->assertOk()->assertJsonPath('recordsTotal',1)->assertJsonPath('recordsFiltered',1);
        }
        $html=$this->table('/invitations')->json('html');
        $this->assertStringContainsString('/invitations/'.$f['invitation']->id.'/reminder',$html);
        $this->assertStringContainsString('data-copy=',$html);
        $this->assertStringContainsString('disabled',$html);
        $this->get('/')->assertOk();
        $this->get('/reports')->assertOk()->assertSee('data-ts-table',false);
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }
    public function test_external_filters_and_related_search_are_combined(): void
    {
        $user=$this->admin(); $a=$this->fixture($user,'A'); $b=$this->fixture($user,'B');
        $b['invitation']->update(['completed_at'=>null]); $b['followup']->update(['status'=>'resolved']); $b['survey']->update(['status'=>'draft']);
        $this->actingAs($user);
        $this->table('/invitations',['status'=>'completed','search'=>['value'=>'Klien B']])->assertOk()->assertJsonPath('recordsTotal',1)->assertJsonPath('recordsFiltered',0);
        $this->table('/followups',['status'=>'open','search'=>['value'=>'Admin Uji']])->assertOk()->assertJsonPath('recordsFiltered',1);
        $this->table('/surveys',['status'=>'draft'])->assertOk()->assertJsonPath('recordsTotal',1);
        $this->table('/responses',['client_id'=>$a['client']->id,'search'=>['value'=>'Proyek B']])->assertOk()->assertJsonPath('recordsFiltered',0);
        $this->table('/responses',['from'=>today()->addDay()->format('Y-m-d')])->assertOk()->assertJsonPath('recordsTotal',0);
    }
    public function test_all_allowed_sort_columns_execute(): void
    {
        $user=$this->admin(); $this->fixture($user,'A'); $this->fixture($user,'B');
        BankQuestion::create(['text'=>'Tanya','category'=>'Umum','type'=>'text','required'=>false]); $this->actingAs($user);
        foreach (['clients'=>[0,1,2,3,4],'surveys'=>[1,2,3,4,5],'invitations'=>[0,1,2,4],'responses'=>[0,1,2,3],'followups'=>[1,2,3,4],'bank'=>[0,1,2,3]] as $table=>$columns) {
            foreach ($columns as $column) $this->table('/'.$table,['order'=>[['column'=>$column,'dir'=>'desc']]])->assertOk();
        }
        $html=$this->table('/followups',['order'=>[['column'=>1,'dir'=>'desc']]])->json('html');
        $this->assertLessThan(strpos($html,'Klien A'),strpos($html,'Klien B'));
    }
    public function test_table_requests_validate_input_and_enforce_roles(): void
    {
        $this->table('/clients')->assertUnauthorized(); $user=$this->admin(); $user->update(['role'=>'viewer']); $this->actingAs($user);
        foreach (['clients','surveys','invitations','bank'] as $table) $this->table('/'.$table)->assertForbidden();
        foreach (['responses','followups'] as $table) $this->table('/'.$table)->assertOk();
        $user->update(['role'=>'admin']);
        $this->table('/clients',['length'=>-1])->assertUnprocessable();
        $this->table('/clients',['order'=>[['column'=>0,'dir'=>'drop table clients']]])->assertUnprocessable();
        $this->table('/clients',['draw'=>'<script>'])->assertUnprocessable();
        $this->table('/clients',['search'=>['value'=>str_repeat('a',256)]])->assertUnprocessable();
    }
    public function test_rows_escape_user_content_and_do_not_expose_access_code(): void
    {
        $user=$this->admin(); $f=$this->fixture($user); $f['client']->update(['name'=>'<script>alert(1)</script>']);
        $f['invitation']->forceFill(['access_code'=>'987654'])->save(); $this->actingAs($user);
        $html=$this->table('/invitations')->assertOk()->json('html');
        $this->assertStringContainsString('&lt;script&gt;',$html);
        $this->assertStringNotContainsString('<script>',$html);
        $this->assertStringNotContainsString('987654',$html);
    }
    public function test_category_score_sort_matches_the_displayed_score(): void
    {
        $user=$this->admin(); $a=$this->fixture($user,'A',5); $b=$this->fixture($user,'B',1);
        foreach ([[$a,1],[$b,5]] as [$f,$value]) {
            $question=$f['survey']->questions()->create(['text'=>'Layanan?','category'=>'Layanan','type'=>'rating','required'=>true,'position'=>1]);
            $f['response']->answers()->create(['question_id'=>$question->id,'question_text'=>'Layanan?','category'=>'Layanan','type'=>'rating','value'=>(string)$value,'comment'=>'Catatan']);
        }
        $this->actingAs($user);
        $html=$this->table('/responses',['category'=>'Layanan','order'=>[['column'=>2,'dir'=>'desc']]])->assertOk()->json('html');
        $this->assertLessThan(strpos($html,'Klien A'),strpos($html,'Klien B'));
        $this->assertSame(5.0,$a['response']->fresh()->score);
    }
}
