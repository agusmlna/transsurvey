<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable {
 use Notifiable;
 protected $fillable=['name','email','password','role'];
 protected $hidden=['password','remember_token'];
 protected function casts(): array {return ['password'=>'hashed'];}
 public function workspaces(){return $this->belongsToMany(Workspace::class,'workspace_user')->withTimestamps();}
 public const ROLES=['superadmin','admin','operation','viewer'];
 public function isSuperadmin(): bool {return $this->role==='superadmin';}
 // Superadmin has every admin permission (it also gets the workspace switcher later).
 public function isAdmin(): bool {return in_array($this->role,['admin','superadmin'],true);}
 public function roleLabel(): string {return ['superadmin'=>'Superadmin','admin'=>'Administrator','operation'=>'Operation'][$this->role] ?? 'Viewer';}
}
