<?php
namespace App\Http\Controllers;
use App\Models\Invitation;
use App\Services\ResponseService;
use Illuminate\Http\Request;
class PublicSurveyController {
 private function find(string $token):Invitation {abort_unless(strlen($token)===64,404);return Invitation::with(['survey.questions','client'])->where('token_hash',hash('sha256',$token))->firstOrFail();}
 public function show(string $token){$invitation=$this->find($token);if($invitation->completed_at)return view('public.thanks');return view('public.survey',['survey'=>$invitation->survey,'invitation'=>$invitation,'preview'=>false,'closed'=>!$invitation->survey->isOpen()]);}
 public function submit(Request $r,string $token,ResponseService $service){$invitation=$this->find($token);$r->validate(['action'=>'required|in:draft,submit','answers'=>'nullable|array']);$draft=$r->input('action')==='draft';$service->save($invitation,$r->only('answers'),$draft);return $draft?back()->with('success','Draf tersimpan. Buka tautan yang sama untuk melanjutkan.'):redirect()->route('survey.public',$token);}
}
