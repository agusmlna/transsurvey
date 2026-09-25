<?php
namespace App\Http\Controllers;
use App\Models\{Survey,BankQuestion};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class SurveyController {
 public function index(Request $r){$q=Survey::withCount(['invitations','responses'])->latest();if(in_array($r->input('status'),['draft','active','closed']))$q->where('status',$r->input('status'));if($r->boolean('template'))$q->where('is_template',true);if($r->filled('q'))$q->where('title','like','%'.$r->input('q').'%');return view('surveys.index',['surveys'=>$q->paginate(12)->withQueryString()]);}
 public function create(){return $this->editor(new Survey(['starts_at'=>today(),'status'=>'draft']));}
 public function edit(Survey $survey){return $this->editor($survey);}
 private function editor(Survey $survey){return view('surveys.form',['survey'=>$survey->load('questions'),'bank'=>BankQuestion::orderBy('category')->get(),'locked'=>$survey->exists && $survey->invitations()->exists()]);}
 public function preview(Survey $survey){return view('public.survey',['survey'=>$survey->load('questions'),'invitation'=>null,'preview'=>true,'closed'=>false]);}
 public function store(Request $r){$survey=DB::transaction(fn()=>$this->persist($r,new Survey(['created_by'=>$r->user()->id])));return redirect()->route('surveys.edit',$survey)->with('success','Kuesioner berhasil dibuat.');}
 public function update(Request $r,Survey $survey){DB::transaction(function()use($r,$survey){$locked=Survey::lockForUpdate()->findOrFail($survey->id);if($locked->version!==$r->integer('version'))throw ValidationException::withMessages(['survey'=>'Kuesioner sudah berubah. Muat ulang sebelum menyimpan.']);$this->persist($r,$locked);});return back()->with('success','Kuesioner berhasil disimpan.');}
 private function persist(Request $r,Survey $survey):Survey {
  $v=$r->validate(['title'=>'required|string|max:255','description'=>'nullable|string|max:5000','status'=>'required|in:draft,active,closed','starts_at'=>'required|date_format:Y-m-d','ends_at'=>'required|date_format:Y-m-d|after_or_equal:starts_at','is_template'=>'nullable|boolean']);
  $locked=$survey->exists && $survey->invitations()->exists();$questions=[];
  if(!$locked){$r->validate(['questions'=>'required|array|min:1|max:100','questions.*.text'=>'required|string|max:2000','questions.*.category'=>'required|string|max:100','questions.*.type'=>'required|in:rating,choice,text','questions.*.required'=>'nullable|boolean','questions.*.options'=>'nullable|string|max:5000']);
   foreach(array_values($r->input('questions')) as $i=>$q){$options=array_values(array_filter(array_map('trim',preg_split('/\R/',$q['options']??'')),fn($x)=>$x!==''));if($q['type']==='choice'&&(count($options)<2 || count($options)!==count(array_unique($options))))throw ValidationException::withMessages(['questions'=>'Pilihan ganda memerlukan minimal dua opsi yang berbeda.']);$questions[]=['text'=>$q['text'],'category'=>$q['category'],'type'=>$q['type'],'required'=>filter_var($q['required']??false,FILTER_VALIDATE_BOOLEAN),'options'=>$q['type']==='choice'?$options:[],'position'=>$i+1];}}
  $survey->fill($v);$survey->is_template=$r->boolean('is_template');$survey->version=($survey->version??0)+1;$survey->save();
  if(!$locked){$survey->questions()->delete();$survey->questions()->createMany($questions);}return $survey;
 }
 public function duplicate(Request $r,Survey $survey){$copy=DB::transaction(function()use($r,$survey){$copy=$survey->replicate();$copy->title=mb_substr($survey->title,0,240).' (salinan)';$copy->status='draft';$copy->created_by=$r->user()->id;$copy->is_demo=false;$copy->version=1;$copy->save();foreach($survey->questions as $q){$copy->questions()->create($q->only(['text','category','type','required','options','position']));}return $copy;});return redirect()->route('surveys.edit',$copy)->with('success','Salinan dibuat. Silakan sesuaikan periode dan pertanyaannya.');}
}
