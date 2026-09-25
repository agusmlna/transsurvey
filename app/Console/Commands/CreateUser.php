<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Hash,Validator};
use Illuminate\Validation\Rules\Password;
class CreateUser extends Command {
 protected $signature='suara:user {--role=admin : admin or viewer}';
 protected $description='Create a Suara user using a hidden password prompt.';
 public function handle():int {
  $role=$this->option('role');$name=$this->ask('Nama');$email=mb_strtolower(trim((string)$this->ask('Email')));$password=$this->secret('Password (minimal 12 karakter, huruf besar/kecil, angka, simbol)');$confirmation=$this->secret('Ulangi password');
  $v=Validator::make(compact('name','email','password','role')+['password_confirmation'=>$confirmation],['name'=>'required|string|max:255','email'=>'required|email|max:255|unique:users,email','role'=>'required|in:admin,viewer','password'=>['required','confirmed',Password::min(12)->mixedCase()->numbers()->symbols()]]);
  if($v->fails()){$this->error($v->errors()->first());return self::FAILURE;}
  User::create(compact('name','email','role')+['password'=>Hash::make($password)]);$this->info('User dibuat. Tidak ada password default.');return self::SUCCESS;
 }
}
