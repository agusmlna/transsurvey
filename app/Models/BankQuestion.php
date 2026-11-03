<?php
namespace App\Models;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
class BankQuestion extends Model {
 use BelongsToWorkspace;
 protected $fillable=['text','category','type','required','options', 'allow_other'];
 protected function casts(): array {return ['required'=>'boolean','options'=>'array', 'allow_other'=>'boolean'];}
 
}
