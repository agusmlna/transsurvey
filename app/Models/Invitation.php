<?php
namespace App\Models;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
class Invitation extends Model {
 use BelongsToWorkspace;
 protected $fillable=['survey_id','client_id','ticket_id','ticket_status','channel','expires_at','token_hash','token','recipient_email','recipient_name','draft_answers','started_at','completed_at','sent_at','reminder_at','reminder_count','is_demo'];
 protected function casts(): array {return ['token'=>'encrypted','access_code'=>'encrypted','draft_answers'=>'array','started_at'=>'datetime','completed_at'=>'datetime','sent_at'=>'datetime','expires_at'=>'datetime','reminder_at'=>'datetime','is_demo'=>'boolean'];}
 protected $hidden=['token','token_hash','draft_answers','access_code','access_code_hash','access_code_fingerprint'];
 public function survey(){return $this->belongsTo(Survey::class);}
 public function client(){return $this->belongsTo(Client::class);}
 public function response(){return $this->hasOne(SurveyResponse::class);}
 public function deliveries(){return $this->hasMany(EmailDelivery::class);}
 // An invitation always lives in its survey's workspace.
 protected function inheritedWorkspaceId(): ?int {return Survey::withoutGlobalScopes()->whereKey($this->survey_id)->value('workspace_id');}
 public function surveyUrl(): string {return route('survey.public',$this->token);}
}
