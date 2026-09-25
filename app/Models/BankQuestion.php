<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BankQuestion extends Model {
 protected $fillable=['text','category','type','required','options'];
 protected function casts(): array {return ['required'=>'boolean','options'=>'array'];}
 
}
