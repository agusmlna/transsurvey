<?php
namespace App\Services;
use App\Models\{Invitation,EmailDelivery};
use App\Jobs\SendSurveyEmail;
use Illuminate\Validation\ValidationException;
class DeliveryService {
 public function enqueue(Invitation $invitation,string $kind):EmailDelivery {
  if(!config('survey.email_enabled') || config('mail.default')!=='smtp')throw ValidationException::withMessages(['mail'=>'Aktifkan SMTP dan SURVEY_EMAIL_ENABLED pada konfigurasi server terlebih dahulu.']);
  if($invitation->is_demo)throw ValidationException::withMessages(['mail'=>'Pengiriman email untuk data contoh dinonaktifkan.']);
  if($invitation->completed_at)throw ValidationException::withMessages(['mail'=>'Survei sudah selesai diisi.']);
  if(!in_array($kind,['invitation','reminder'],true))throw new \InvalidArgumentException('Invalid delivery kind');
  if($kind==='reminder' && $invitation->reminder_count>=config('survey.max_reminders'))throw ValidationException::withMessages(['mail'=>'Batas reminder sudah tercapai.']);
  $key=$kind.':'.$invitation->id.($kind==='reminder'?':'.today()->format('Y-m-d'):'');
  $delivery=EmailDelivery::firstOrCreate(['dedupe_key'=>$key],['invitation_id'=>$invitation->id,'kind'=>$kind,'status'=>'pending']);
  if($delivery->status==='sent')throw ValidationException::withMessages(['mail'=>'Email ini sudah dikirim. Reminder dibatasi satu kali per hari.']);
  if($delivery->status==='skipped')throw ValidationException::withMessages(['mail'=>'Pengiriman sudah dibatalkan karena survei tidak aktif atau sudah diisi.']);
  if($delivery->status==='failed')$delivery->update(['status'=>'pending','last_error'=>null,'queued_at'=>null]);
  $this->dispatch($delivery);return $delivery;
 }
 public function dispatch(EmailDelivery $delivery):void {
  if($delivery->status!=='pending')return;
  if($delivery->queued_at && $delivery->queued_at->gt(now()->subMinutes(10)))return;
  SendSurveyEmail::dispatch($delivery->id)->afterCommit();
  $delivery->update(['queued_at'=>now()]);
 }
}
