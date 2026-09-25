<?php
namespace App\Console\Commands;
use App\Models\EmailDelivery;
use App\Services\DeliveryService;
use Illuminate\Console\Command;
class DispatchPendingMail extends Command {
 protected $signature='surveys:dispatch-pending';protected $description='Recover pending emails not picked up by the queue.';
 public function handle(DeliveryService $service):int {
  if(!config('survey.email_enabled'))return self::SUCCESS;
  EmailDelivery::where('status','pending')->where(fn($q)=>$q->whereNull('queued_at')->orWhere('queued_at','<',now()->subMinutes(10)))->chunkById(100,function($rows)use($service){foreach($rows as $row)$service->dispatch($row);});return self::SUCCESS;
 }
}
