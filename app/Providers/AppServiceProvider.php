<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
class AppServiceProvider extends ServiceProvider {
 public function register(): void {}
 public function boot(): void {
  RateLimiter::for('login',fn(Request $r)=>[Limit::perMinute(5)->by(mb_strtolower((string)$r->input('email')).'|'.$r->ip()),Limit::perMinute(25)->by($r->ip())]);
  RateLimiter::for('respond',fn(Request $r)=>Limit::perMinute(30)->by($r->ip().'|'.hash('sha256',(string)$r->route('token'))));
  \Illuminate\Pagination\Paginator::useBootstrapFive();
 }
}
