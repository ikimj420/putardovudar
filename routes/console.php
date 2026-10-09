<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jednom dnevno ujutru po beogradskom vremenu. Mutex ističe posle tri sata, da prekinut posao ne blokira sledeći dan.
Schedule::command('uvoz:dnevno')
    ->dailyAt('06:00')
    ->timezone('Europe/Belgrade')
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/uvoz-dnevno.log'));
