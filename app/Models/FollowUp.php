<?php
namespace App\Models;
use App\Services\CurrentWorkspace;
use Illuminate\Database\Eloquent\{Builder, Model};
class FollowUp extends Model {
 // Follow-ups have no workspace column; they follow their (scoped) response.
 protected static function booted(): void {static::addGlobalScope('workspace',function(Builder $q){if(app(CurrentWorkspace::class)->filterId()!==null)$q->whereHas('response');});}
 protected $fillable=['survey_response_id','assigned_to','status','due_at','notes','resolved_at','version'];
 protected function casts(): array {return ['due_at'=>'date','resolved_at'=>'datetime','version'=>'integer'];}
 public function response(){return $this->belongsTo(SurveyResponse::class,'survey_response_id');}
 public function assignee(){return $this->belongsTo(User::class,'assigned_to');}
}
