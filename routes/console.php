<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('surveys:remind')->hourly()->withoutOverlapping();
Schedule::command('surveys:dispatch-pending')->everyFiveMinutes()->withoutOverlapping();
