<?php
namespace Tests\Feature;

use App\Models\{User, Survey, Question, QuestionImport, Client, Invitation};
use App\Services\{QuestionImportService, ResponseService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Mail, Queue};
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as Reader;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class QuestionImportTest extends TestCase
{
    use RefreshDatabase;
    private array $temporaryFiles = [];
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->temporaryFiles as $file) if (is_file($file)) unlink($file);
    }
    private function admin(): User
    {
        $user = User::create(['name'=>'Import Admin','email'=>Str::random(10).'@example.test','password'=>'TestOnly123!','role'=>'admin']);
        $this->actingAs($user); return $user;
    }
    private function survey(User $user, string $status = 'draft'): Survey
    {
        return Survey::create(['title'=>'Existing survey','status'=>$status,'starts_at'=>today(),'ends_at'=>today()->addMonth(),'created_by'=>$user->id]);
    }
    private function row(string $text = 'Question?', string $type = 'rating', int $order = 1): array
    {
        return [$order,$text,'Service',$type,'Ya','','Tidak'];
    }
    private function upload(array $rows, ?callable $edit = null): UploadedFile
    {
        $book = app(QuestionImportService::class)->template(); $sheet = $book->getSheetByName('Pertanyaan');
        foreach ($rows as $i=>$row) foreach ($row as $j=>$value) $sheet->setCellValueExplicit([$j+1,$i+2],(string)$value,DataType::TYPE_STRING);
        if ($edit) $edit($book);
        $path = tempnam(sys_get_temp_dir(),'qi-test-'); $this->temporaryFiles[] = $path;
        (new Xlsx($book))->save($path); $book->disconnectWorksheets();
        return new UploadedFile($path,'questions.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
    }
    private function preview(array $rows, ?Survey $survey = null, array $extra = [])
    {
        return $this->post(route('surveys.import.preview'),array_merge([
            'file'=>$this->upload($rows),'survey_id'=>$survey?->id,'title'=>'New imported survey','description'=>'Test description',
            'starts_at'=>today()->format('Y-m-d'),'ends_at'=>today()->addWeek()->format('Y-m-d'),
        ],$extra));
    }
    public function test_template_has_empty_input_examples_validation_and_private_download(): void
    {
        $this->admin();
        $response=$this->get(route('surveys.import.template'))->assertOk()->assertDownload('TransSurvey_Template_Pertanyaan.xlsx');
        $path=$response->baseResponse->getFile()->getPathname(); $this->temporaryFiles[]=$path;
        $book=(new Reader())->load($path);
        $this->assertSame(['Pertanyaan','Contoh','Petunjuk'],$book->getSheetNames()); $sheet=$book->getSheetByName('Pertanyaan');
        $this->assertSame(QuestionImportService::HEADERS,$sheet->rangeToArray('A1:G1')[0]);
        $this->assertNull($sheet->getCell('B2')->getValue());
        $this->assertSame('"rating,text,choice,multiple"',$sheet->getCell('D2')->getDataValidation()->getFormula1());
        $book->disconnectWorksheets(); $this->assertNotEmpty(app(QuestionImportService::class)->inspect($path)['errors']);
    }
    public function test_new_draft_import_is_atomic_ordered_and_idempotent(): void
    {
        $this->admin();
        $this->preview([$this->row('Last question','text',4),[2,'Single?','Service','choice','Ya',"A\nB",'Ya'],[3,'Multiple?','Service','multiple','Tidak',"C\nD",'Ya'],$this->row('First rating','rating',1)],extra:['is_template'=>'1'])->assertRedirect();
        $this->assertDatabaseCount('surveys',0); $this->assertDatabaseCount('questions',0); $batch=QuestionImport::firstOrFail();
        $this->get(route('surveys.import.show',$batch->token))->assertOk()->assertSee('First rating');
        $url=route('surveys.import.confirm',$batch->token);
        $this->post($url,['rows'=>[['text'=>'Tampered']],'survey_id'=>999])->assertRedirect(); $survey=Survey::firstOrFail();
        $this->assertSame('draft',$survey->status); $this->assertTrue($survey->is_template);
        $this->assertSame(['First rating','Single?','Multiple?','Last question'],$survey->questions->pluck('text')->all());
        $this->assertSame([1,2,3,4],$survey->questions->pluck('position')->all());
        $this->assertSame(['A','B'],$survey->questions[1]->options); $this->assertTrue($survey->questions[2]->allow_other); $this->assertFalse($survey->questions[2]->required);
        $this->assertNull($batch->fresh()->payload); $this->post($url)->assertRedirect(route('surveys.edit',$survey));
        $this->assertDatabaseCount('surveys',1); $this->assertDatabaseCount('questions',4);
    }
    public function test_append_preserves_existing_ids_and_blocks_stale_editor(): void
    {
        $user=$this->admin(); $survey=$this->survey($user);
        $q=$survey->questions()->create(['text'=>'Existing','category'=>'Service','type'=>'text','required'=>false,'position'=>7]); $oldVersion=$survey->version;
        $this->preview([$this->row()],$survey)->assertRedirect();
        $this->post(route('surveys.import.confirm',QuestionImport::first()->token))->assertRedirect();
        $this->assertDatabaseHas('questions',['id'=>$q->id,'text'=>'Existing','position'=>7]);
        $this->assertSame(8,$survey->questions()->where('text','Question?')->first()->position);
        $this->put(route('surveys.update',$survey),['version'=>$oldVersion])->assertSessionHasErrors('survey');
        $this->assertDatabaseCount('questions',2);
    }
    public function test_duplicates_in_file_and_existing_questions_are_rejected(): void
    {
        $user=$this->admin(); $survey=$this->survey($user);
        $survey->questions()->create(['text'=>'Same question','category'=>'Service','type'=>'text','required'=>false,'position'=>1]);
        $this->preview([$this->row(' SAME   question ','rating',1),$this->row('same question','rating',2)],$survey)->assertStatus(422)->assertSee(__('question_import.duplicate_existing'))->assertSee(__('question_import.duplicate_file',['row'=>2]));
        $this->assertDatabaseCount('question_imports',0); $this->assertDatabaseCount('questions',1);
    }
    public function test_bad_row_prevents_all_rows_from_importing(): void
    {
        $this->admin();
        $this->preview([$this->row(),[2,'Invalid','Service','nps','Maybe','','Ya']])->assertStatus(422)->assertSee(__('question_import.type'))->assertSee(__('question_import.required'));
        $this->assertDatabaseCount('question_imports',0); $this->assertDatabaseCount('questions',0);
    }
    public function test_row_validation_handles_options_lengths_and_missing_values(): void
    {
        $this->admin();
        $cases=[['','Missing order','Service','rating','Ya','','Tidak'],[1,'','Service','text','Ya','','Tidak'],[1,'Missing category','','text','Ya','','Tidak'],[1,'Bad options','Service','choice','Ya',"A\nA",'Tidak'],[1,'One option','Service','multiple','Ya','A','Tidak'],[1,'Rating options','Service','rating','Ya','1,2,3','Tidak'],[1,'Reserved option','Service','choice','Ya',"A\n__other__",'Ya'],[1,str_repeat('a',2001),'Service','text','Ya','','Tidak'],[1,'Too long options','Service','multiple','Ya',str_repeat('A',5000)."\nB",'Tidak']];
        foreach($cases as $row) $this->preview([$row])->assertStatus(422);
        $this->preview([$this->row('A'),$this->row('B')])->assertStatus(422)->assertSee(__('question_import.order_duplicate'));
        $this->assertDatabaseCount('question_imports',0);
    }
    public function test_formulas_are_not_evaluated_and_preview_escapes_html(): void
    {
        $this->admin(); $file=$this->upload([$this->row()],fn($b)=>$b->getSheetByName('Pertanyaan')->setCellValue('B2','=1+2'));
        $this->preview([],extra:['file'=>$file])->assertStatus(422)->assertSee(__('question_import.literal',['cell'=>'B2']));
        $this->preview([$this->row('<script>alert(1)</script>')])->assertRedirect();
        $this->get(route('surveys.import.show',QuestionImport::first()->token))->assertSee('&lt;script&gt;',false)->assertDontSee('<script>alert(1)</script>',false);
    }
    public function test_corrupt_wrong_sheet_header_and_extra_columns_are_rejected(): void
    {
        $this->admin();
        foreach([fn($b)=>$b->getSheetByName('Pertanyaan')->setTitle('Wrong'),fn($b)=>$b->getSheetByName('Pertanyaan')->setCellValue('B1','Question'),fn($b)=>$b->getSheetByName('Pertanyaan')->setCellValue('H2','Extra'),fn($b)=>$b->getSheetByName('Pertanyaan')->mergeCells('B2:C2')] as $edit) $this->preview([],extra:['file'=>$this->upload([$this->row()],$edit)])->assertSessionHasErrors('file');
        $this->preview([],extra:['file'=>UploadedFile::fake()->createWithContent('bad.xlsx','not a workbook')])->assertSessionHasErrors('file');
    }
    public function test_100_question_limit_includes_existing_questions(): void
    {
        $user=$this->admin(); $survey=$this->survey($user);
        for($i=1;$i<=100;$i++) $survey->questions()->create(['text'=>'Existing '.$i,'category'=>'Service','type'=>'text','required'=>false,'position'=>$i]);
        $this->preview([$this->row()],$survey)->assertStatus(422)->assertSee(__('question_import.limit'));
        $this->preview(array_map(fn($i)=>$this->row('New '.$i,'text',$i),range(1,101)))->assertSessionHasErrors('file');
    }
    public function test_permissions_and_preview_ownership(): void
    {
        $this->get(route('surveys.import.create'))->assertRedirect(route('login')); $user=$this->admin();
        $this->preview([$this->row()])->assertRedirect(); $batch=QuestionImport::first(); $this->admin();
        $this->get(route('surveys.import.show',$batch->token))->assertNotFound(); $this->post(route('surveys.import.confirm',$batch->token))->assertNotFound();
        $user->update(['role'=>'viewer']); $this->actingAs($user);
        $this->get(route('surveys.import.create'))->assertForbidden(); $this->get(route('surveys.import.template'))->assertForbidden();
        $this->preview([$this->row()])->assertForbidden(); $this->post(route('surveys.import.confirm',$batch->token))->assertForbidden();
    }
    public function test_expired_and_changed_previews_cannot_commit(): void
    {
        $user=$this->admin(); $survey=$this->survey($user); $this->preview([$this->row()],$survey); $batch=QuestionImport::first();
        $survey->increment('version'); $this->post(route('surveys.import.confirm',$batch->token))->assertSessionHasErrors('survey'); $this->assertDatabaseCount('questions',0);
        $batch->update(['expires_at'=>now()->subMinute()]); $this->post(route('surveys.import.confirm',$batch->token))->assertSessionHasErrors('file');
        $this->get(route('surveys.import.show',$batch->token))->assertRedirect(route('surveys.import.create'));
    }
    public function test_status_and_invitations_are_checked_again_at_confirmation(): void
    {
        $user=$this->admin(); $survey=$this->survey($user,'active'); $this->preview([$this->row()],$survey)->assertSessionHasErrors('survey');
        $survey->update(['status'=>'draft']); $this->preview([$this->row()],$survey); $batch=QuestionImport::first();
        $client=Client::create(['name'=>'Test','contact'=>'PIC','email'=>'pic@example.test','project'=>'Test project','active'=>true]); $token=Str::random(64);
        Invitation::create(['survey_id'=>$survey->id,'client_id'=>$client->id,'token'=>$token,'token_hash'=>hash('sha256',$token),'recipient_name'=>'PIC','recipient_email'=>'pic@example.test']);
        $this->post(route('surveys.import.confirm',$batch->token))->assertSessionHasErrors('survey'); $this->assertDatabaseCount('questions',0);
    }
    public function test_write_error_rolls_back_questions_and_new_survey(): void
    {
        $this->admin(); $this->preview([$this->row('First','rating',1),$this->row('Second','text',2)]); $batch=QuestionImport::first();
        Question::creating(function($q){if($q->text==='Second') throw new \RuntimeException('Simulated write failure');}); $this->withoutExceptionHandling();
        try {$this->post(route('surveys.import.confirm',$batch->token)); $this->fail('Expected failure');} catch(\RuntimeException $e){$this->assertSame('Simulated write failure',$e->getMessage());}
        $this->assertDatabaseCount('questions',0); $this->assertDatabaseCount('surveys',0); $this->assertNull($batch->fresh()->imported_at);
    }
    public function test_imported_types_work_in_existing_survey_and_low_score_rules(): void
    {
        Mail::fake(); Queue::fake(); $this->admin();
        $this->preview([$this->row(),[2,'Choose','Service','choice','Ya',"A\nB",'Ya'],[3,'Pick several','Service','multiple','Ya',"C\nD",'Ya'],$this->row('Notes','text',4)]);
        $this->post(route('surveys.import.confirm',QuestionImport::first()->token)); $survey=Survey::first(); $survey->update(['status'=>'active']);
        $this->get(route('surveys.preview',$survey))->assertOk()->assertSee('Pick several');
        $client=Client::create(['name'=>'Test','contact'=>'PIC','email'=>'pic@example.test','project'=>'Test project','active'=>true]); $token=Str::random(64);
        $inv=Invitation::create(['survey_id'=>$survey->id,'client_id'=>$client->id,'token'=>$token,'token_hash'=>hash('sha256',$token),'recipient_name'=>'PIC','recipient_email'=>'pic@example.test']); $q=$survey->questions;
        $input=['answers'=>[$q[0]->id=>['value'=>'2'],$q[1]->id=>['value'=>'__other__','other'=>'Other choice'],$q[2]->id=>['value'=>['C','__other__'],'other'=>'Other multiple'],$q[3]->id=>['value'=>'Text answer']]];
        try{app(ResponseService::class)->save($inv,$input);$this->fail('Low rating should require comment');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('answers.'.$q[0]->id.'.comment',$e->errors());}
        $input['answers'][$q[0]->id]['comment']='Please improve'; $response=app(ResponseService::class)->save($inv,$input);
        $this->assertSame(4,$response->answers()->count()); $this->assertEquals(2,$response->score);
        $summary = app(\App\Services\ReportService::class)->summary(\Illuminate\Http\Request::create('/', 'GET', ['survey_id' => $survey->id, 'category' => 'Service']));
        $this->assertEquals(2, $summary['categories']['Service']);
        $this->assertSame(1, $summary['feedback']['attention']);
        $this->assertCount(1, $summary['responses']);
    }
    public function test_english_pages_and_csrf(): void
    {
        $this->admin();$this->withSession(['locale'=>'en']);
        $this->get(route('surveys.import.create'))->assertOk()->assertSee('Import questions')->assertDontSee('question_import.');
        $this->get(route('surveys.index'))->assertOk()->assertSee(route('surveys.import.create'));
        $this->app['env']='staging';$this->post(route('surveys.import.preview'))->assertStatus(419);
    }
}
