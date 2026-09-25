<?php
namespace App\Services;
use App\Models\{Invitation,SurveyResponse,FollowUp};
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class ResponseService {
 public function save(Invitation $invitation, array $input, bool $draft=false): ?SurveyResponse {
  return DB::transaction(function() use($invitation,$input,$draft){
   $inv=Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
   if($inv->completed_at){throw ValidationException::withMessages(['survey'=>'Respons Anda sudah tercatat.']);}
   $survey=$inv->survey()->firstOrFail();
   if(!$survey->isOpen()){throw ValidationException::withMessages(['survey'=>'Survei sedang tidak menerima respons.']);}
   $questions=$survey->questions; $rules=[];
   foreach($questions as $q){
    $prefix='answers.'.$q->id;
    $rules[$prefix.'.value']=[(!$draft && $q->required)?'required':'nullable','string','max:5000'];
    if($q->type==='rating'){$rules[$prefix.'.value'][]=Rule::in(['1','2','3','4','5']);}
    if($q->type==='choice'){$rules[$prefix.'.value'][]=Rule::in($q->options??[]);}
    $low=$q->type==='rating' && in_array((string)data_get($input,$prefix.'.value'),['1','2','3'],true);
    $rules[$prefix.'.comment']=[(!$draft && $low)?'required':'nullable','string','max:5000'];
   }
   Validator::make($input,$rules,['required'=>'Pertanyaan wajib atau komentar untuk skor 1–3 belum diisi.','in'=>'Pilihan jawaban tidak valid.'])->validate();
   $answers=[];$scores=[];$hasLow=false;
   foreach($questions as $q){
    $value=trim((string)data_get($input,'answers.'.$q->id.'.value',''));
    $comment=trim((string)data_get($input,'answers.'.$q->id.'.comment',''));
    $answers[$q->id]=['value'=>$value,'comment'=>$comment];
    if($q->type==='rating' && $value!==''){$scores[]=(int)$value;$hasLow=$hasLow || (int)$value<=3;}
   }
   $inv->started_at ??= now();
   if($draft){$inv->draft_answers=$answers;$inv->save();return null;}
   $response=SurveyResponse::create(['invitation_id'=>$inv->id,'survey_id'=>$survey->id,'client_id'=>$inv->client_id,'score'=>count($scores)?round(array_sum($scores)/count($scores),2):null,'submitted_at'=>now()]);
   foreach($questions as $q){$response->answers()->create(['question_id'=>$q->id,'question_text'=>$q->text,'category'=>$q->category,'type'=>$q->type,'value'=>$answers[$q->id]['value'],'comment'=>$answers[$q->id]['comment']]);}
   $inv->forceFill(['completed_at'=>now(),'draft_answers'=>null])->save();
   if($hasLow){FollowUp::create(['survey_response_id'=>$response->id,'status'=>'open','due_at'=>today()->addDays(3)]);}
   return $response;
  },3);
 }
}
