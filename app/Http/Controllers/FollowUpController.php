<?php
namespace App\Http\Controllers;
use App\Models\{FollowUp,User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class FollowUpController {
 public function index(Request $r){$q=FollowUp::with(['response.client','response.survey','assignee'])->latest();if(in_array($r->input('status'),['open','in_progress','resolved']))$q->where('status',$r->input('status'));return view('followups.index',['followups'=>$q->paginate(20)->withQueryString()]);}
 public function edit(FollowUp $followup){return view('followups.form',['followup'=>$followup->load(['response.client','response.answers']),'users'=>User::orderBy('name')->get()]);}
 public function update(Request $r,FollowUp $followup){$v=$r->validate(['assigned_to'=>'required|exists:users,id','due_at'=>'required|date_format:Y-m-d','status'=>'required|in:open,in_progress,resolved','notes'=>'nullable|required_if:status,resolved|string|max:10000','version'=>'required|integer']);DB::transaction(function()use($v,$followup){$f=FollowUp::lockForUpdate()->findOrFail($followup->id);if($f->version!==(int)$v['version'])throw ValidationException::withMessages(['followup'=>'Data berubah. Muat ulang halaman.']);$f->fill($v);$f->version++;$f->resolved_at=$v['status']==='resolved'?($f->resolved_at??now()):null;$f->save();});return redirect()->route('followups.index')->with('success','Tindak lanjut diperbarui.');}
}
