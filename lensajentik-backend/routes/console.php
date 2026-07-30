<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('skor-risiko:refresh')->everySixHours();
// Ganti weekly -> daily: command sudah punya logika cek "belum input >7 hari", jadi tidak spam
Schedule::command('reminder-kader:cek')->daily();
// Skor risiko murni cuaca (confidence lemah) — pagi hari setiap hari
Schedule::command('skor-risiko:refresh-cuaca')->dailyAt('06:00');
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
