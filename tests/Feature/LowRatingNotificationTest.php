<?php
namespace Tests\Feature;

use App\Mail\SurveyCompletedMail;
use App\Models\{Client, Invitation, Survey, SurveyResponse, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LowRatingNotificationTest extends TestCase
{
    use RefreshDatabase;
    private function response(array $answers): SurveyResponse
    {
        $admin=User::create(['name'=>'Admin Uji','email'=>'admin@example.test','password'=>'Testing12345!','role'=>'admin']);
        $client=Client::create(['name'=>'Klien Uji','contact'=>'PIC Uji','email'=>'pic@example.test','project'=>'IT','active'=>true]);
        $survey=Survey::create(['title'=>'Survei Layanan','status'=>'active','starts_at'=>today()->subDay(),'ends_at'=>today()->addWeek(),'created_by'=>$admin->id]);
        $token=Str::random(64);
        $invitation=Invitation::create(['survey_id'=>$survey->id,'client_id'=>$client->id,'token'=>$token,'token_hash'=>hash('sha256',$token),'recipient_name'=>$client->contact,'recipient_email'=>$client->email,'completed_at'=>now()]);
        $ratings=collect($answers)->filter(fn($a)=>$a['type']==='rating' && in_array($a['value'],['1','2','3','4','5'],true));
        $response=SurveyResponse::create(['survey_id'=>$survey->id,'client_id'=>$client->id,'invitation_id'=>$invitation->id,'score'=>$ratings->isEmpty()?null:$ratings->avg(fn($a)=>(int)$a['value']),'submitted_at'=>now()]);
        foreach($answers as $i=>$answer){
            $q=$survey->questions()->create(['text'=>'Pertanyaan '.($i+1),'category'=>'Layanan','type'=>$answer['type'],'required'=>false,'position'=>$i+1]);
            $response->answers()->create(['question_id'=>$q->id,'question_text'=>$q->text,'category'=>'Layanan','type'=>$answer['type'],'value'=>$answer['value'],'comment'=>$answer['comment']??'']);
        }
        return $response;
    }
    public function test_one_low_answer_alerts_even_when_average_is_above_four(): void
    {
        $response=$this->response([['type'=>'rating','value'=>'3','comment'=>'Mohon lebih cepat.'],['type'=>'rating','value'=>'5'],['type'=>'rating','value'=>'5']]);
        $this->assertGreaterThan(4,$response->score);
        $mail=new SurveyCompletedMail($response,'Admin Uji');$html=$mail->render();
        $this->assertStringStartsWith('[Perlu tindak lanjut]',$mail->subject);
        $this->assertStringContainsString('1 jawaban rating bernilai di bawah 4',$html);
        $this->assertStringContainsString('Nilai: 3 / 5',$html);
        $this->assertStringContainsString('Mohon lebih cepat.',$html);
        $this->assertStringNotContainsString('Nilai: 5 / 5',$html);
        $this->assertStringContainsString(route('responses.show',$response),$html);
        $this->assertStringNotContainsString($response->invitation->token,$html);
    }
    public function test_four_and_five_keep_the_regular_completion_email(): void
    {
        $response=$this->response([['type'=>'rating','value'=>'4'],['type'=>'rating','value'=>'5']]);
        $mail=new SurveyCompletedMail($response,'Admin Uji');$html=$mail->render();
        $this->assertSame('Survei selesai: Survei Layanan',$mail->subject);
        $this->assertStringNotContainsString('Ada rating di bawah standar',$html);
        $this->assertStringContainsString('Survei sudah selesai diisi',$html);
    }
    public function test_text_choice_and_unanswered_ratings_do_not_trigger_alerts(): void
    {
        $response=$this->response([['type'=>'text','value'=>'1'],['type'=>'choice','value'=>'3'],['type'=>'rating','value'=>'']]);
        $mail=new SurveyCompletedMail($response,'Admin Uji');$html=$mail->render();
        $this->assertSame('Survei selesai: Survei Layanan',$mail->subject);
        $this->assertStringNotContainsString('Ada rating di bawah standar',$html);
    }
    public function test_all_low_ratings_are_included_and_comments_are_escaped(): void
    {
        $response=$this->response([['type'=>'rating','value'=>'1','comment'=>'<script>alert(1)</script>'],['type'=>'rating','value'=>'2','comment'=>'Perlu perbaikan'],['type'=>'rating','value'=>'3','comment'=>'Terlambat']]);
        $response->setRelation('answers',$response->answers()->limit(1)->get());
        $html=(new SurveyCompletedMail($response,'Admin Uji'))->render();
        $this->assertStringContainsString('3 jawaban rating bernilai di bawah 4',$html);
        foreach([1,2,3] as $v)$this->assertStringContainsString('Nilai: '.$v.' / 5',$html);
        $this->assertStringContainsString('&lt;script&gt;',$html);
        $this->assertStringNotContainsString('<script>alert(1)</script>',$html);
    }
}
