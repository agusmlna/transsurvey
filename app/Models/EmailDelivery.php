<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmailDelivery extends Model {
 protected $fillable=['invitation_id','kind','dedupe_key','status','sent_at','queued_at','last_error'];
 protected function casts(): array {return ['sent_at'=>'datetime','queued_at'=>'datetime'];}
 public function invitation(){return $this->belongsTo(Invitation::class);}
}
