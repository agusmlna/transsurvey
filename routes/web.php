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

Route::post('/language', [App\Http\Controllers\LanguageController::class, 'update'])
    ->name('language.update');

/*
|--------------------------------------------------------------------------
| Guest Routes
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])
        ->name('login');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.submit');
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

    Route::post('/s/{token}/verify', [PublicSurveyController::class, 'verify'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('survey.verify');

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
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');

    Route::get('/', DashboardController::class)
        ->name('dashboard');

    // Responses
    Route::get('/responses', [ResponseController::class, 'index'])
        ->name('responses.index');

    Route::get('/responses/{response}', [ResponseController::class, 'show'])
        ->name('responses.show');

    // Reports
    Route::get('/reports', [ReportController::class, 'index'])
        ->name('reports.index');

    Route::get('/reports/export.csv', [ReportController::class, 'csv'])
        ->name('reports.csv');

    Route::get('/reports/export.pdf', [ReportController::class, 'pdf'])
        ->name('reports.pdf');

    Route::get('/reports/export.xlsx', [ReportController::class, 'xlsx'])
        ->name('reports.xlsx');

    // Follow-ups
    Route::get('/followups', [FollowUpController::class, 'index'])
        ->name('followups.index');

    /*
    |--------------------------------------------------------------------------
    | Admin Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('admin')->group(function () {
        Route::get('/data-cleanup', [App\Http\Controllers\DataDeletionController::class, 'index'])->name('data-cleanup.index');
        Route::get('/data-cleanup/history', [App\Http\Controllers\DataDeletionController::class, 'history'])->name('data-cleanup.history');
        Route::post('/data-cleanup/preview', [App\Http\Controllers\DataDeletionController::class, 'preview'])->name('data-cleanup.preview');
        Route::get('/data-cleanup/preview/{token}', [App\Http\Controllers\DataDeletionController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->name('data-cleanup.show');
        Route::post('/data-cleanup/delete/{token}', [App\Http\Controllers\DataDeletionController::class, 'destroy'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:10,1')->name('data-cleanup.destroy');

        // Surveys
        Route::get('/surveys/import', [App\Http\Controllers\QuestionImportController::class, 'create'])->name('surveys.import.create');
        Route::get('/surveys/import/template', [App\Http\Controllers\QuestionImportController::class, 'template'])->name('surveys.import.template');
        Route::post('/surveys/import/preview', [App\Http\Controllers\QuestionImportController::class, 'preview'])->middleware('throttle:10,1')->name('surveys.import.preview');
        Route::get('/surveys/import/preview/{token}', [App\Http\Controllers\QuestionImportController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->name('surveys.import.show');
        Route::post('/surveys/import/confirm/{token}', [App\Http\Controllers\QuestionImportController::class, 'confirm'])->where('token', '[A-Za-z0-9]{64}')->name('surveys.import.confirm');

        Route::resource('surveys', SurveyController::class)
            ->except(['show', 'destroy']);

        Route::get('/surveys/{survey}/preview', [SurveyController::class, 'preview'])
            ->name('surveys.preview');

        Route::post('/surveys/{survey}/duplicate', [SurveyController::class, 'duplicate'])
            ->name('surveys.duplicate');

        // Clients
        Route::resource('clients', ClientController::class)
            ->except(['show', 'destroy']);

        // Invitations
        Route::get('/invitations', [InvitationController::class, 'index'])
            ->name('invitations.index');

        Route::post('/invitations', [InvitationController::class, 'store'])
            ->name('invitations.store');

        Route::post('/invitations/{invitation}/send', [InvitationController::class, 'send'])
            ->name('invitations.send');

        Route::post('/invitations/{invitation}/send-template/{kind}', [InvitationController::class, 'sendTemplate'])
            ->where('kind', 'not_started|save_draft')
            ->name('invitations.send-template');

        Route::post('/invitations/{invitation}/reminder', [InvitationController::class, 'remind'])
            ->name('invitations.remind');

        // Bank
        Route::get('/bank', [BankController::class, 'index'])
            ->name('bank.index');

        Route::post('/bank', [BankController::class, 'store'])
            ->name('bank.store');

        // Follow-ups (Admin Edit)
        Route::get('/followups/{followup}/edit', [FollowUpController::class, 'edit'])
            ->name('followups.edit');

        Route::put('/followups/{followup}', [FollowUpController::class, 'update'])
            ->name('followups.update');
    });
});