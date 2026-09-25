<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Question extends Model {
 protected $fillable=['survey_id','text','category','type','required','options','position'];
 protected function casts(): array {return ['required'=>'boolean','options'=>'array'];}
 public function survey(){return $this->belongsTo(Survey::class);}
}
