<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function form()
    {
        return view('auth.login');
    }
    public function login(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (!Auth::attempt($v, false)) {
            throw ValidationException::withMessages(['email' => __('Email atau password tidak sesuai.')]);
        }
        // Akun operation/SSO hanya boleh masuk lewat tombol TransCXP.
        $user = $r->user();
        if (!$user->isAdmin() && ($user->role === 'operation' || $user->external_source !== null) && !config('cxp.allow_operation_password')) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
            throw ValidationException::withMessages(['email' => __('Akun operation hanya dapat masuk melalui tombol dari TransCXP.')]);
        }
        $r->session()->regenerate();
        return redirect()->intended(route('dashboard'))->with('success', __('Login berhasil. Selamat datang di TransSurvey.'));
    }
    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect()->route('login')->with('success', __('Anda berhasil logout dari TransSurvey.'));
    }
}
