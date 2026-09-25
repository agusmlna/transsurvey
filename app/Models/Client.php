<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Client extends Model {
 protected $fillable=['name','contact','email','project','active','is_demo'];
 protected function casts(): array {return ['active'=>'boolean','is_demo'=>'boolean'];}
 public function invitations(){return $this->hasMany(Invitation::class);}
}
