<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ═══════════════════════════════════════════════════════════════
// Scheduled Jobs — semua di-scope ke JAWA BARAT (kode 32)
// supaya refresh data harian ringan & tidak overload API.
// Logic untuk luar Jawa Barat tetap ada, tinggal ubah/hapus
// opsi --provinsi atau jalankan command manual.
// ═══════════════════════════════════════════════════════════════

Schedule::command('skor-risiko:refresh --provinsi=32')->everySixHours();
// Ganti weekly -> daily: command sudah punya logika cek "belum input >7 hari", jadi tidak spam
Schedule::command('reminder-kader:cek')->daily();
// Skor risiko murni cuaca (confidence lemah) — pagi hari setiap hari
Schedule::command('skor-risiko:refresh-cuaca --provinsi=32')->dailyAt('06:00');
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
