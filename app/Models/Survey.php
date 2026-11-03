<?php
namespace App\Models;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
class Survey extends Model {
 use BelongsToWorkspace;
 protected $fillable=['title','type','description','status','starts_at','ends_at','is_template','is_demo','created_by','version'];
 protected function casts(): array {return ['starts_at'=>'immutable_date','ends_at'=>'immutable_date','is_template'=>'boolean','is_demo'=>'boolean','version'=>'integer'];}
 public function questions(){return $this->hasMany(Question::class)->orderBy('position');}
 public function invitations(){return $this->hasMany(Invitation::class);}
 public function responses(){return $this->hasMany(SurveyResponse::class);}
 // CSAT templates have no end date (ends_at null): open until closed.
 public function isOpen(): bool {return $this->status==='active' && $this->starts_at->startOfDay()->lte(now()) && ($this->ends_at===null || $this->ends_at->endOfDay()->gte(now()));}
}
