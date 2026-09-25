<?php
namespace App\Services;
use App\Models\{SurveyResponse,Invitation,Survey,FollowUp};
use Illuminate\Http\Request;
class ReportService {
 public function responses(Request $r){
  $q=SurveyResponse::query()->with(['client','survey','answers','invitation']);
  if($r->filled('client_id'))$q->where('client_id',$r->integer('client_id'));
  if($r->filled('survey_id'))$q->where('survey_id',$r->integer('survey_id'));
  if($r->filled('project'))$q->whereHas('client',fn($c)=>$c->where('project',$r->input('project')));
  if($r->filled('from'))$q->where('submitted_at','>=',$r->date('from')->startOfDay());
  if($r->filled('to'))$q->where('submitted_at','<=',$r->date('to')->endOfDay());
  if(in_array($r->input('source'),['real','demo']))$q->whereHas('invitation',fn($i)=>$i->where('is_demo',$r->input('source')==='demo'));
  return $q;
 }
 public function summary(Request $r): array {
  $responses=$this->responses($r)->latest('submitted_at')->get();
  $inv=Invitation::query();
  if($r->filled('client_id'))$inv->where('client_id',$r->integer('client_id'));
  if($r->filled('survey_id'))$inv->where('survey_id',$r->integer('survey_id'));
  if($r->filled('project'))$inv->whereHas('client',fn($c)=>$c->where('project',$r->input('project')));
  if($r->filled('from'))$inv->where('created_at','>=',$r->date('from')->startOfDay());
  if($r->filled('to'))$inv->where('created_at','<=',$r->date('to')->endOfDay());
  if(in_array($r->input('source'),['real','demo']))$inv->where('is_demo',$r->input('source')==='demo');
  $invCount=(clone $inv)->count();$complete=(clone $inv)->whereNotNull('completed_at')->count();
  $ratings=$responses->flatMap->answers->filter(fn($a)=>$a->type==='rating' && $a->value!==null && $a->value!=='');
  $categories=$ratings->groupBy('category')->map(fn($rows)=>round($rows->avg(fn($a)=>(int)$a->value),2))->sortDesc();
  $trends=$responses->whereNotNull('score')->groupBy(fn($r)=>$r->submitted_at->format('Y-m'))->sortKeys()->map(fn($rows)=>round($rows->avg('score'),2));
  return compact('responses','categories','trends','invCount','complete')+['average'=>$responses->whereNotNull('score')->avg('score'),'rate'=>$invCount?round(100*$complete/$invCount):0,'active'=>Survey::where('status','active')->whereDate('starts_at','<=',today())->whereDate('ends_at','>=',today())->count(),'openFollow'=>FollowUp::where('status','!=','resolved')->count()];
 }
 public function validateFilters(Request $r):void{$r->validate(['client_id'=>'nullable|integer|exists:clients,id','survey_id'=>'nullable|integer|exists:surveys,id','project'=>'nullable|string|max:255','from'=>'nullable|date_format:Y-m-d','to'=>array_filter(['nullable','date_format:Y-m-d',$r->filled('from')?'after_or_equal:from':null]),'source'=>'nullable|in:real,demo']);}
}
