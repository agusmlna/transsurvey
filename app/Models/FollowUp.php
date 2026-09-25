<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FollowUp extends Model {
 protected $fillable=['survey_response_id','assigned_to','status','due_at','notes','resolved_at','version'];
 protected function casts(): array {return ['due_at'=>'date','resolved_at'=>'datetime','version'=>'integer'];}
 public function response(){return $this->belongsTo(SurveyResponse::class,'survey_response_id');}
 public function assignee(){return $this->belongsTo(User::class,'assigned_to');}
}
