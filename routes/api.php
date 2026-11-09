<?php

use App\Http\Controllers\CxpLaunchSessionController;
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
