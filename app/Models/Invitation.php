<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Invitation extends Model {
 protected $fillable=['survey_id','client_id','token_hash','token','recipient_email','recipient_name','draft_answers','started_at','completed_at','sent_at','reminder_at','reminder_count','is_demo'];
 protected function casts(): array {return ['token'=>'encrypted','draft_answers'=>'array','started_at'=>'datetime','completed_at'=>'datetime','sent_at'=>'datetime','reminder_at'=>'datetime','is_demo'=>'boolean'];}
 protected $hidden=['token','token_hash','draft_answers'];
 public function survey(){return $this->belongsTo(Survey::class);}
 public function client(){return $this->belongsTo(Client::class);}
 public function response(){return $this->hasOne(SurveyResponse::class);}
 public function deliveries(){return $this->hasMany(EmailDelivery::class);}
 public function surveyUrl(): string {return route('survey.public',$this->token);}
}
