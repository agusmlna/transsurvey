<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Answer extends Model {
 protected $fillable=['survey_response_id','question_id','question_text','category','type','value','comment'];
 protected function casts(): array {return [];}
 
}
