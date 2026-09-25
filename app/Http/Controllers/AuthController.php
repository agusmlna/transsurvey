<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
class AuthController {
 public function form(){return view('auth.login');}
 public function login(Request $r){$v=$r->validate(['email'=>'required|email','password'=>'required|string']);if(!Auth::attempt($v,false)){throw ValidationException::withMessages(['email'=>'Email atau password tidak sesuai.']);}$r->session()->regenerate();return redirect()->intended(route('dashboard'));}
 public function logout(Request $r){Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return redirect()->route('login');}
}
