<?php
namespace App\Jobs;
use App\Models\{EmailDelivery,Invitation};
use App\Mail\SurveyInvitationMail;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB,Mail};
class SendSurveyEmail implements ShouldQueue,ShouldBeUnique {
 use Queueable;
 public int $tries=3; public int $timeout=60; public int $uniqueFor=600;
 public function __construct(public int $deliveryId){}
 public function uniqueId():string{return (string)$this->deliveryId;}
 public function backoff():array{return [60,180,300];}
 public function handle():void {
  if(!config('survey.email_enabled') || config('mail.default')!=='smtp')throw new \RuntimeException('SMTP sending is not enabled.');
  DB::transaction(function(){
   $delivery=EmailDelivery::lockForUpdate()->findOrFail($this->deliveryId);
   if(in_array($delivery->status,['sent','skipped']))return;
   $inv=Invitation::with('survey')->lockForUpdate()->findOrFail($delivery->invitation_id);
   if($inv->completed_at || $inv->is_demo || !$inv->survey->isOpen() || ($delivery->kind==='reminder' && $inv->reminder_count>=config('survey.max_reminders'))){$delivery->update(['status'=>'skipped']);return;}
   Mail::to($inv->recipient_email)->send(new SurveyInvitationMail($inv,$delivery->kind==='reminder'));
   $delivery->update(['status'=>'sent','sent_at'=>now(),'last_error'=>null]);
   $inv->sent_at ??= now();if($delivery->kind==='reminder')$inv->reminder_count++;
   $inv->reminder_at=now()->addDays(config('survey.reminder_days'));$inv->save();
  });
 }
 public function failed(?\Throwable $e):void {EmailDelivery::whereKey($this->deliveryId)->where('status','!=','sent')->update(['status'=>'failed','last_error'=>'Pengiriman gagal. Periksa koneksi SMTP dan log worker.']);}
}
