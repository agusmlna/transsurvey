<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('surveys:remind')->hourly()->withoutOverlapping();
Schedule::command('surveys:dispatch-pending')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('cxp:csat-send --send --only-autosend')->hourly()->withoutOverlapping()
    ->when(fn () => (bool) config('cxp.csat.enabled'));
Schedule::command('cxp:csat-wa-status --limit=100')->everyFifteenMinutes()->withoutOverlapping()
    ->when(fn () => (bool) config('cxp.csat.enabled'));
