<?php
namespace App\Console\Commands;
use App\Models\Invitation;
use App\Services\DeliveryService;
use Illuminate\Console\Command;
class SendReminders extends Command {
 protected $signature='surveys:remind';protected $description='Queue due reminders for unanswered, active surveys.';
 public function handle(DeliveryService $service):int {
  if(!config('survey.email_enabled')){$this->info('Email belum diaktifkan; tidak ada pengiriman.');return self::SUCCESS;}
  $queued=0;
  Invitation::whereNull('completed_at')->where('is_demo',false)->whereNotNull('sent_at')->where('reminder_at','<=',now())->where('reminder_count','<',config('survey.max_reminders'))->whereHas('survey',fn($q)=>$q->where('status','active')->whereDate('starts_at','<=',today())->whereDate('ends_at','>=',today()))->chunkById(100,function($rows)use($service,&$queued){foreach($rows as $i){try{$service->enqueue($i,'reminder');$queued++;}catch(\Illuminate\Validation\ValidationException $e){$this->warn('Undangan #'.$i->id.': '.$e->validator->errors()->first());}}});
  $this->info($queued.' reminder diperiksa/diantrikan.');return self::SUCCESS;
 }
}
