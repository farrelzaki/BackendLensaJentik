<?php
namespace App\Services;

use App\Models\Wilayah;
use App\Models\DataCuaca;
use App\Models\AbjLaporan;
use App\Models\LaporanWarga;
use App\Models\SkorRisiko;
use App\Models\PrediksiRisiko;
use Carbon\Carbon;

/**
 * Engine utama kalkulasi skor risiko LensaJentik.
 *
 * Formula menggabungkan 3 sumber data:
 *   1. Cuaca (Open-Meteo)          — via SkorCuacaCalculator (rolling 7-hari + elevasi)
 *   2. ABJ Kader (pemeriksaan)     — via AbjLaporan (30 hari terakhir)
 *   3. Laporan Warga (crowdsource) — via LaporanWarga (30 hari terakhir, status aktif)
 *
 * Confidence "kuat" (ada ABJ):  40% Cuaca + 35% ABJ + 25% Laporan
 * Confidence "lemah" (no ABJ):  65% Cuaca + 35% Laporan
 *
 * Single source of truth — dipakai oleh:
 *   - SkorRisikoController@show  (real-time)
 *   - HitungSkorRisikoJob        (background/queue)
 */
class RiskScoreService
{
    protected array $urutanLevel = ['rendah' => 1, 'sedang' => 2, 'tinggi' => 3];

    public function __construct(
        protected NotificationService $notificationService,
        protected WeatherService $weatherService,
    ) {}

    /**
     * Hitung dan simpan skor risiko untuk 1 wilayah — historis + prediksi.
     *
     * @return array Dua elemen: ['historis' => SkorRisiko[], 'prediksi' => PrediksiRisiko[]]
     */
    public function hitungDanSimpan(Wilayah $wilayah, string $jenisPenyakit = 'dbd'): array
    {
        // 1. Fetch cuaca 14 hari historis + 16 hari forecast
        $dataCuaca = $this->weatherService->fetchFullRange($wilayah);

        if (empty($dataCuaca)) {
            return ['historis' => [], 'prediksi' => []];
        }

        // Urutkan berdasarkan tanggal
        usort($dataCuaca, fn($a, $b) => $a->tanggal <=> $b->tanggal);

        // 2. Hitung ABJ & laporan warga (komponen non-cuaca)
        $abjInfo = $this->hitungSkorAbj($wilayah);
        $laporanScore = $this->hitungSkorLaporan($wilayah);
        $confidence = $abjInfo['confidence'];
        $elevasi = $wilayah->elevasi !== null ? (float) $wilayah->elevasi : null;

        // 3. Hitung rolling 7-day precipitation
        $hujanPerTanggal = [];
        foreach ($dataCuaca as $cuaca) {
            $hujanPerTanggal[$cuaca->tanggal->toDateString()] = (float) ($cuaca->curah_hujan ?? 0);
        }
        $rolling7Hari = SkorCuacaCalculator::hitungRolling7Hari($hujanPerTanggal);

        // 4. Hitung skor untuk setiap tanggal
        $today = Carbon::today('Asia/Jakarta');
        $tanggalPerhitungan = $today->toDateString();
        $historis = [];
        $prediksi = [];

        foreach ($dataCuaca as $cuaca) {
            $tanggalStr = $cuaca->tanggal->toDateString();
            $isForecast = $tanggalStr > $today->toDateString();

            $suhu   = (float) ($cuaca->suhu_avg ?? 27.0);
            $hujan7 = $rolling7Hari[$tanggalStr] ?? 0.0;
            $lembap = (float) ($cuaca->kelembapan_avg ?? 60.0);

            // Hitung skor cuaca dengan formula standar (rolling 7-hari)
            $skorCuaca = SkorCuacaCalculator::hitungSkorCuaca($suhu, $hujan7, $lembap);

            // Terapkan penalti elevasi
            $skorCuacaFinal = SkorCuacaCalculator::terapkanPenaltiElevasi($skorCuaca, $elevasi);

            // Gabung dengan data non-cuaca
            if ($confidence === 'kuat') {
                $skorAkhir = ($skorCuacaFinal * 0.40) + ($abjInfo['skor'] * 0.35) + ($laporanScore * 0.25);
            } else {
                $skorAkhir = ($skorCuacaFinal * 0.65) + ($laporanScore * 0.35);
            }

            $skorAkhir = round(min(100.0, max(0.0, $skorAkhir)), 2);
            $levelBaru = SkorCuacaCalculator::tentukanLevel($skorAkhir);

            $faktor = [
                'skor_cuaca'           => round($skorCuacaFinal, 2),
                'skor_cuaca_mentah'    => round($skorCuaca, 2),
                'f_suhu'               => round(SkorCuacaCalculator::fSuhu($suhu), 2),
                'f_hujan'              => round(SkorCuacaCalculator::fHujan($hujan7), 2),
                'f_lembap'             => round(SkorCuacaCalculator::fLembap($lembap), 2),
                'suhu'                 => $suhu,
                'curah_hujan'          => (float) ($cuaca->curah_hujan ?? 0),
                'akumulasi_hujan_7hari'=> round($hujan7, 2),
                'kelembapan'           => $lembap,
                'elevasi'              => $elevasi,
                'penalti_elevasi'      => $skorCuacaFinal !== $skorCuaca,
                'skor_abj'             => $abjInfo['skor'],
                'skor_laporan'         => $laporanScore,
                'abj_persen'           => $abjInfo['abj_persen'],
            ];

            if ($isForecast) {
                // ── Simpan ke prediksi_risiko ──────────────────────────
                $record = PrediksiRisiko::updateOrCreate(
                    [
                        'wilayah_kode'        => $wilayah->kode,
                        'jenis_penyakit'      => $jenisPenyakit,
                        'tanggal_prediksi'    => $tanggalStr,
                        'tanggal_perhitungan' => $tanggalPerhitungan,
                    ],
                    [
                        'skor'               => $skorAkhir,
                        'level_risiko'       => $levelBaru,
                        'confidence_level'   => $confidence,
                        'faktor_perhitungan' => $faktor,
                    ]
                );
                $prediksi[] = $record;
            } else {
                // ── Simpan ke skor_risiko ──────────────────────────────
                // Cek skor sebelumnya untuk notifikasi
                $skorSebelumnya = SkorRisiko::where('wilayah_kode', $wilayah->kode)
                    ->where('jenis_penyakit', $jenisPenyakit)
                    ->where('tanggal', $tanggalStr)
                    ->where('is_prediksi', false)
                    ->first();

                $record = SkorRisiko::updateOrCreate(
                    [
                        'wilayah_kode'   => $wilayah->kode,
                        'jenis_penyakit' => $jenisPenyakit,
                        'tanggal'        => $tanggalStr,
                        'is_prediksi'    => false,
                    ],
                    [
                        'skor'               => $skorAkhir,
                        'level_risiko'       => $levelBaru,
                        'confidence_level'   => $confidence,
                        'faktor_perhitungan' => $faktor,
                    ]
                );

                // Notifikasi jika level naik
                if ($skorSebelumnya && isset($this->urutanLevel[$levelBaru])
                    && $this->urutanLevel[$levelBaru] > $this->urutanLevel[$skorSebelumnya->level_risiko]) {
                    $this->notificationService->notifikasiKenaikanRisiko(
                        $wilayah, $jenisPenyakit, $skorSebelumnya->level_risiko, $levelBaru
                    );
                }

                // Notifikasi cuaca ekstrem (curah hujan > 30 mm/hari)
                if ($cuaca->curah_hujan && $cuaca->curah_hujan > 30) {
                    $this->notificationService->notifikasiCuacaEkstrem($wilayah, $cuaca->curah_hujan);
                }

                $historis[] = $record;
            }
        }

        return ['historis' => $historis, 'prediksi' => $prediksi];
    }

    /* ── Komponen non-cuaca ────────────────────────────────────────────────── */

    /**
     * Hitung skor ABJ dari data pemeriksaan kader (30 hari terakhir).
     *
     * @return array{skor: float|null, abj_persen: float|null, confidence: 'kuat'|'lemah'}
     */
    protected function hitungSkorAbj(Wilayah $wilayah): array
    {
        $batasWaktu = Carbon::now()->subDays(30);

        $rataAbj = AbjLaporan::where('wilayah_kode', $wilayah->kode)
            ->where('tanggal_pemeriksaan', '>=', $batasWaktu)
            ->avg('abj_persen');

        if ($rataAbj === null) {
            return ['skor' => null, 'abj_persen' => null, 'confidence' => 'lemah'];
        }

        // ABJ tinggi = risiko rendah, ABJ rendah = risiko tinggi
        $skorRisiko = max(0, min(100, 100 - $rataAbj));

        return [
            'skor'       => round($skorRisiko, 2),
            'abj_persen' => round((float) $rataAbj, 2),
            'confidence' => 'kuat',
        ];
    }

    /**
     * Hitung skor dari laporan warga aktif (30 hari terakhir).
     * Setiap laporan aktif berkontribusi 20 poin, maks 100.
     */
    protected function hitungSkorLaporan(Wilayah $wilayah): float
    {
        $batasWaktu = Carbon::now()->subDays(30);

        $jumlahAktif = LaporanWarga::where('wilayah_kode', $wilayah->kode)
            ->where('created_at', '>=', $batasWaktu)
            ->where('status', '!=', 'selesai')
            ->count();

        return min(100, $jumlahAktif * 20);
    }
}
