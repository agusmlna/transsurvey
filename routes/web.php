<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{AuthController,DashboardController,SurveyController,ClientController,InvitationController,PublicSurveyController,ResponseController,BankController,FollowUpController,ReportController};
Route::middleware('guest')->group(function(){Route::get('/login',[AuthController::class,'form'])->name('login');Route::post('/login',[AuthController::class,'login'])->middleware('throttle:login')->name('login.submit');});
Route::get('/s/{token}',[PublicSurveyController::class,'show'])->where('token','[A-Za-z0-9]{64}')->middleware('throttle:respond')->name('survey.public');
Route::post('/s/{token}',[PublicSurveyController::class,'submit'])->where('token','[A-Za-z0-9]{64}')->middleware('throttle:respond')->name('survey.submit');
Route::middleware('auth')->group(function(){
 Route::post('/logout',[AuthController::class,'logout'])->name('logout');
 Route::get('/',DashboardController::class)->name('dashboard');
 Route::get('/responses',[ResponseController::class,'index'])->name('responses.index');Route::get('/responses/{response}',[ResponseController::class,'show'])->name('responses.show');
 Route::get('/reports',[ReportController::class,'index'])->name('reports.index');Route::get('/reports/export.csv',[ReportController::class,'csv'])->name('reports.csv');
 Route::get('/followups',[FollowUpController::class,'index'])->name('followups.index');
 Route::middleware('admin')->group(function(){
  Route::resource('surveys',SurveyController::class)->except(['show','destroy']);
  Route::get('/surveys/{survey}/preview',[SurveyController::class,'preview'])->name('surveys.preview');Route::post('/surveys/{survey}/duplicate',[SurveyController::class,'duplicate'])->name('surveys.duplicate');
  Route::resource('clients',ClientController::class)->except(['show','destroy']);
  Route::get('/invitations',[InvitationController::class,'index'])->name('invitations.index');Route::post('/invitations',[InvitationController::class,'store'])->name('invitations.store');Route::post('/invitations/{invitation}/send',[InvitationController::class,'send'])->name('invitations.send');
  Route::get('/bank',[BankController::class,'index'])->name('bank.index');Route::post('/bank',[BankController::class,'store'])->name('bank.store');
  Route::get('/followups/{followup}/edit',[FollowUpController::class,'edit'])->name('followups.edit');Route::put('/followups/{followup}',[FollowUpController::class,'update'])->name('followups.update');
 });
});
