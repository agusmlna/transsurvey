<?php

use App\Http\Controllers\{
    AuthController,
    DashboardController,
    SurveyController,
    ClientController,
    InvitationController,
    PublicSurveyController,
    ResponseController,
    BankController,
    FollowUpController,
    ReportController,
};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest Routes
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.submit');
});

/*
|--------------------------------------------------------------------------
| Public Survey Routes
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:respond')->group(function () {
    Route::get('/s/{token}', [PublicSurveyController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('survey.public');
    
    Route::post('/s/{token}', [PublicSurveyController::class, 'submit'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('survey.submit');
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    // Responses
    Route::get('/responses', [ResponseController::class, 'index'])->name('responses.index');
    Route::get('/responses/{response}', [ResponseController::class, 'show'])->name('responses.show');

    // Reports
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export.csv', [ReportController::class, 'csv'])->name('reports.csv');

    // Follow-ups
    Route::get('/followups', [FollowUpController::class, 'index'])->name('followups.index');

    /*
    |--------------------------------------------------------------------------
    | Admin Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('admin')->group(function () {
        // Surveys
        Route::resource('surveys', SurveyController::class)->except(['show', 'destroy']);
        Route::get('/surveys/{survey}/preview', [SurveyController::class, 'preview'])->name('surveys.preview');
        Route::post('/surveys/{survey}/duplicate', [SurveyController::class, 'duplicate'])->name('surveys.duplicate');

        // Clients
        Route::resource('clients', ClientController::class)->except(['show', 'destroy']);

        // Invitations
        Route::get('/invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('/invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('/invitations/{invitation}/send', [InvitationController::class, 'send'])->name('invitations.send');
        Route::post('/invitations/{invitation}/reminder',[InvitationController::class,'remind'])->name('invitations.remind');

        // Bank
        Route::get('/bank', [BankController::class, 'index'])->name('bank.index');
        Route::post('/bank', [BankController::class, 'store'])->name('bank.store');

        // Follow-ups (Admin Edit)
        Route::get('/followups/{followup}/edit', [FollowUpController::class, 'edit'])->name('followups.edit');
        Route::put('/followups/{followup}', [FollowUpController::class, 'update'])->name('followups.update');
    });
});