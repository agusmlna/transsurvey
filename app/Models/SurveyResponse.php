<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SurveyResponse extends Model {
 protected $fillable=['invitation_id','survey_id','client_id','score','submitted_at'];
 protected function casts(): array {return ['score'=>'float','submitted_at'=>'datetime'];}
 public function survey(){return $this->belongsTo(Survey::class);}
 public function client(){return $this->belongsTo(Client::class);}
 public function invitation(){return $this->belongsTo(Invitation::class);}
 public function answers(){return $this->hasMany(Answer::class);}
 public function followUp(){return $this->hasOne(FollowUp::class);}
 public function getCategoryScoresAttribute(): array
{
    return $this->answers
        ->filter(fn($a) => $a->type === 'rating' && $a->value !== null && $a->value !== '')
        ->groupBy('category')
        ->map(fn($rows) => round($rows->avg(fn($a) => (int) $a->value), 2))
        ->all();
}
}