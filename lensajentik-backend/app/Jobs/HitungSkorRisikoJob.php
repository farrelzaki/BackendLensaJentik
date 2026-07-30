<?php

namespace App\Jobs;

use App\Models\Wilayah;
use App\Models\SkorRisiko;
use App\Models\PrediksiRisiko;
use App\Services\SkorCuacaCalculator;
use App\Services\WeatherService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hitung skor risiko DBD/Malaria per wilayah MURNI dari data cuaca
 * (confidence_level = "lemah") — tanpa data lapangan (ABJ/jentik).
 *
 * Alur:
 *   1. Fetch 14 hari historis + 16 hari forecast Open-Meteo (1 request)
 *   2. Hitung rolling 7-day precipitation sum
 *   3. Hitung skor cuaca per tanggal dengan formula baru
 *   4. Terapkan penalti elevasi (> 1000 mdpl)
 *   5. Simpan historis -> skor_risiko, forecast -> prediksi_risiko
 *
 * Usage:
 *   HitungSkorRisikoJob::dispatch('3271010');
 *   HitungSkorRisikoJob::dispatch('3271010', 'malaria');
 */
class HitungSkorRisikoJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $wilayahKode   Kode wilayah (kecamatan) yg akan dihitung
     * @param  string  $jenisPenyakit  'dbd' | 'malaria'
     */
    public function __construct(
        public string $wilayahKode,
        public string $jenisPenyakit = 'dbd',
    ) {}

    public function handle(WeatherService $weatherService): void
    {
        $wilayah = Wilayah::find($this->wilayahKode);

        if (!$wilayah) {
            logger()->warning("HitungSkorRisikoJob: Wilayah {$this->wilayahKode} tidak ditemukan.");
            return;
        }

        // ── 1. Fetch cuaca full-range ──────────────────────────────────────
        $dataCuaca = $weatherService->fetchFullRange($wilayah);

        if (empty($dataCuaca)) {
            logger()->warning("HitungSkorRisikoJob: Gagal fetch cuaca untuk {$wilayah->nama}.");
            return;
        }

        // Urutkan berdasarkan tanggal
        usort($dataCuaca, fn ($a, $b) => $a->tanggal <=> $b->tanggal);

        // ── 2. Hitung rolling 7-day precipitation ──────────────────────────
        $hujanPerTanggal = [];
        foreach ($dataCuaca as $cuaca) {
            $hujanPerTanggal[$cuaca->tanggal->toDateString()] = (float) ($cuaca->curah_hujan ?? 0);
        }
        $rolling7Hari = SkorCuacaCalculator::hitungRolling7Hari($hujanPerTanggal);

        // ── 3. Hitung skor per tanggal ─────────────────────────────────────
        $today = Carbon::today('Asia/Jakarta');
        $tanggalPerhitungan = $today->toDateString();
        $elevasi = $wilayah->elevasi !== null ? (float) $wilayah->elevasi : null;

        foreach ($dataCuaca as $cuaca) {
            $tanggalStr = $cuaca->tanggal->toDateString();
            $isForecast = $tanggalStr > $today->toDateString();

            $suhu     = $cuaca->suhu_avg ?? 27.0;
            $hujan7   = $rolling7Hari[$tanggalStr] ?? 0.0;
            $lembap   = $cuaca->kelembapan_avg ?? 60.0;

            // Hitung skor cuaca mentah
            $skorCuaca = SkorCuacaCalculator::hitungSkorCuaca(
                (float) $suhu,
                (float) $hujan7,
                (float) $lembap,
            );

            // Terapkan penalti elevasi
            $skorFinal = SkorCuacaCalculator::terapkanPenaltiElevasi($skorCuaca, $elevasi);
            $level = SkorCuacaCalculator::tentukanLevel($skorFinal);

            $faktor = [
                'skor_cuaca'   => $skorCuaca,
                'f_suhu'       => SkorCuacaCalculator::fSuhu((float) $suhu),
                'f_hujan'      => SkorCuacaCalculator::fHujan((float) $hujan7),
                'f_lembap'     => SkorCuacaCalculator::fLembap((float) $lembap),
                'suhu'         => (float) $suhu,
                'akumulasi_hujan_7hari' => (float) $hujan7,
                'kelembapan'   => (float) $lembap,
                'elevasi'      => $elevasi,
                'penalti_elevasi' => $skorFinal !== $skorCuaca,
            ];

            if ($isForecast) {
                // ── Simpan ke prediksi_risiko ──────────────────────────────
                PrediksiRisiko::updateOrCreate(
                    [
                        'wilayah_kode'       => $wilayah->kode,
                        'jenis_penyakit'     => $this->jenisPenyakit,
                        'tanggal_prediksi'   => $tanggalStr,
                        'tanggal_perhitungan'=> $tanggalPerhitungan,
                    ],
                    [
                        'skor'               => $skorFinal,
                        'level_risiko'       => $level,
                        'confidence_level'   => 'lemah',
                        'faktor_perhitungan' => $faktor,
                    ]
                );
            } else {
                // ── Simpan ke skor_risiko (historis) ───────────────────────
                SkorRisiko::updateOrCreate(
                    [
                        'wilayah_kode'   => $wilayah->kode,
                        'jenis_penyakit' => $this->jenisPenyakit,
                        'tanggal'        => $tanggalStr,
                        'is_prediksi'    => false,
                    ],
                    [
                        'skor'               => $skorFinal,
                        'level_risiko'       => $level,
                        'confidence_level'   => 'lemah',
                        'faktor_perhitungan' => $faktor,
                    ]
                );
            }
        }

        logger()->info(
            "HitungSkorRisikoJob: Selesai untuk {$wilayah->nama} " .
            '(' . count($dataCuaca) . ' hari, jenis: ' . $this->jenisPenyakit . ')'
        );
    }
}
