<?php

namespace App\Jobs;

use App\Models\Wilayah;
use App\Services\RiskScoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hitung skor risiko DBD/Malaria untuk 1 wilayah via RiskScoreService.
 *
 * Mencakup:
 *   - Fetch cuaca Open-Meteo (14 hari historis + 16 hari forecast)
 *   - Kalkulasi skor cuaca (rolling 7-hari + penalti elevasi)
 *   - ABJ kader (jika tersedia → confidence "kuat")
 *   - Laporan warga aktif
 *   - Simpan ke skor_risiko (historis) & prediksi_risiko (forecast)
 *
 * Usage:
 *   HitungSkorRisikoJob::dispatch('3271010');
 *   HitungSkorRisikoJob::dispatchSync('3271010');
 *   HitungSkorRisikoJob::dispatch('3271010', 'malaria');
 */
class HitungSkorRisikoJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $wilayahKode,
        public string $jenisPenyakit = 'dbd',
    ) {}

    public function handle(RiskScoreService $riskScoreService): void
    {
        $wilayah = Wilayah::find($this->wilayahKode);

        if (!$wilayah) {
            logger()->warning("HitungSkorRisikoJob: Wilayah {$this->wilayahKode} tidak ditemukan.");
            return;
        }

        $hasil = $riskScoreService->hitungDanSimpan($wilayah, $this->jenisPenyakit);

        $nHistoris = count($hasil['historis']);
        $nPrediksi = count($hasil['prediksi']);

        logger()->info(
            "HitungSkorRisikoJob: {$wilayah->nama} — " .
            "{$nHistoris} hari historis, {$nPrediksi} hari prediksi " .
            "(jenis: {$this->jenisPenyakit})"
        );
    }
}
