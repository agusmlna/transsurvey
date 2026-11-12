<?php

use App\Http\Controllers\CxpLaunchSessionController;
use App\Http\Controllers\CxpCsatLinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TransCXP launch session (tanpa JWT)
|--------------------------------------------------------------------------
| Server-to-server: TransCXP mengirim user/project (dan tiket opsional),
| TransSurvey membalas kode sekali pakai untuk diarahkan ke browser.
*/
Route::post('/cxp/launch-sessions', CxpLaunchSessionController::class)
    ->middleware('throttle:cxp-launch-api')
    ->name('cxp.launch-sessions.store');

/*
|--------------------------------------------------------------------------
| TransCXP CSAT link API
|--------------------------------------------------------------------------
| Server-to-server: TransCXP meminta tautan CSAT satu tiket, lalu mengirimkan
| tautan itu sendiri ke customer. Tidak ada email yang diantrekan.
*/
Route::post('/cxp/csat-links', CxpCsatLinkController::class)
    ->middleware('throttle:cxp-csat-link-api')
    ->name('cxp.csat-links.store');
