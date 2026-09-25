<?php
namespace App\Http\Controllers;
use App\Models\{Invitation,Survey,Client};
use App\Services\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\{Str};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class InvitationController {
 public function index(Request $r){$q=Invitation::with(['survey','client','deliveries'])->latest();if($r->input('status')==='completed')$q->whereNotNull('completed_at');if($r->input('status')==='pending')$q->whereNull('completed_at');return view('invitations.index',['invitations'=>$q->paginate(20)->withQueryString(),'surveys'=>Survey::where('status','active')->get(),'clients'=>Client::where('active',true)->orderBy('name')->get()]);}
 public function store(Request $r){$v=$r->validate(['survey_id'=>'required|exists:surveys,id','client_ids'=>'required|array|min:1|max:100','client_ids.*'=>'required|integer|distinct|exists:clients,id']);$count=DB::transaction(function()use($v){$survey=Survey::lockForUpdate()->findOrFail($v['survey_id']);if($survey->status!=='active'||$survey->ends_at->endOfDay()->isPast())throw ValidationException::withMessages(['survey_id'=>'Pilih survei aktif yang belum berakhir.']);$created=0;foreach($v['client_ids'] as $id){if(Invitation::where('survey_id',$survey->id)->where('client_id',$id)->exists())continue;$c=Client::where('active',true)->findOrFail($id);$token=Str::random(64);Invitation::create(['survey_id'=>$survey->id,'client_id'=>$c->id,'token'=>$token,'token_hash'=>hash('sha256',$token),'recipient_name'=>$c->contact,'recipient_email'=>$c->email,'is_demo'=>$survey->is_demo||$c->is_demo]);$created++;}return $created;});return back()->with('success',"{$count} undangan baru dibuat; undangan yang sudah ada dilewati. Salin tautan atau klik Kirim email setelah memeriksa penerima.");}
 public function send(Invitation $invitation,DeliveryService $delivery){if(!$invitation->survey->isOpen())throw ValidationException::withMessages(['mail'=>'Email hanya dapat dikirim dalam periode survei aktif.']);$delivery->enqueue($invitation,$invitation->sent_at?'reminder':'invitation');return back()->with('success','Email masuk antrean. Pantau status pengiriman di daftar undangan.');}
}
