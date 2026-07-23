<?php
namespace App\Services;

use App\Models\Wilayah;
use App\Models\DataCuaca;
use App\Models\AbjLaporan;
use App\Models\LaporanWarga;
use App\Models\SkorRisiko;
use Carbon\Carbon;

class RiskScoreService
{
    protected array $urutanLevel = ['rendah' => 1, 'sedang' => 2, 'tinggi' => 3];

    public function __construct(protected NotificationService $notificationService) {}

    public function hitungDanSimpan(Wilayah $wilayah, string $jenisPenyakit = 'dbd'): array
    {
        $abjInfo = $this->hitungSkorAbj($wilayah);
        $laporanScore = $this->hitungSkorLaporan($wilayah);
        $confidence = $abjInfo['confidence'];

        $dataCuaca = DataCuaca::where('wilayah_kode', $wilayah->kode)
            ->orderBy('tanggal')
            ->get();

        $hasil = [];

        foreach ($dataCuaca as $cuaca) {
            $cuacaScore = $this->hitungSkorCuaca($cuaca);

            if ($confidence === 'kuat') {
                $skorAkhir = ($cuacaScore * 0.40) + ($abjInfo['skor'] * 0.35) + ($laporanScore * 0.25);
            } else {
                $skorAkhir = ($cuacaScore * 0.65) + ($laporanScore * 0.35);
            }

            $skorAkhir = round(min(100, max(0, $skorAkhir)), 2);
            $levelBaru = $this->tentukanLevel($skorAkhir);

            // Ambil skor SEBELUM di-update, buat dibandingkan (cuma relevan utk hari ini, bukan forecast)
            $skorSebelumnya = null;
            if (!$cuaca->is_forecast) {
                $skorSebelumnya = SkorRisiko::where('wilayah_kode', $wilayah->kode)
                    ->where('jenis_penyakit', $jenisPenyakit)
                    ->where('tanggal', $cuaca->tanggal)
                    ->where('is_prediksi', false)
                    ->first();
            }

            $skor = SkorRisiko::updateOrCreate(
                [
                    'wilayah_kode' => $wilayah->kode,
                    'jenis_penyakit' => $jenisPenyakit,
                    'tanggal' => $cuaca->tanggal,
                    'is_prediksi' => $cuaca->is_forecast,
                ],
                [
                    'skor' => $skorAkhir,
                    'level_risiko' => $levelBaru,
                    'confidence_level' => $confidence,
                    'faktor_perhitungan' => [
                        'skor_cuaca' => round($cuacaScore, 2),
                        'skor_abj' => $abjInfo['skor'],
                        'skor_laporan' => $laporanScore,
                        'suhu' => $cuaca->suhu_avg,
                        'kelembapan' => $cuaca->kelembapan_avg,
                        'curah_hujan' => $cuaca->curah_hujan,
                        'abj_persen' => $abjInfo['abj_persen'],
                    ],
                ]
            );

            // Trigger notifikasi kalau level naik dibanding sebelumnya
            if ($skorSebelumnya && $this->urutanLevel[$levelBaru] > $this->urutanLevel[$skorSebelumnya->level_risiko]) {
                $this->notificationService->notifikasiKenaikanRisiko(
                    $wilayah, $jenisPenyakit, $skorSebelumnya->level_risiko, $levelBaru
                );
            }

            // Trigger notifikasi cuaca ekstrem (curah hujan >30mm/hari, ambang subjektif tapi wajar utk hujan lebat)
            if (!$cuaca->is_forecast && $cuaca->curah_hujan && $cuaca->curah_hujan > 30) {
                $this->notificationService->notifikasiCuacaEkstrem($wilayah, $cuaca->curah_hujan);
            }

            $hasil[] = $skor;
        }

        return $hasil;
    }

    protected function hitungSkorCuaca(DataCuaca $cuaca): float
    {
        $suhu = $cuaca->suhu_avg ?? 27;
        if ($suhu >= 25 && $suhu <= 30) {
            $suhuScore = 100;
        } else {
            $selisih = $suhu < 25 ? (25 - $suhu) : ($suhu - 30);
            $suhuScore = max(0, 100 - ($selisih * 10));
        }

        $kelembapan = $cuaca->kelembapan_avg ?? 60;
        $kelembapanScore = max(0, min(100, ($kelembapan - 40) * (100 / 60)));

        $hujan = $cuaca->curah_hujan ?? 0;
        $hujanScore = max(0, min(100, ($hujan / 20) * 100));

        return ($suhuScore * 0.4) + ($kelembapanScore * 0.3) + ($hujanScore * 0.3);
    }

    protected function hitungSkorAbj(Wilayah $wilayah): array
    {
        $batasWaktu = Carbon::now()->subDays(30);

        $rataAbj = AbjLaporan::where('wilayah_kode', $wilayah->kode)
            ->where('tanggal_pemeriksaan', '>=', $batasWaktu)
            ->avg('abj_persen');

        if ($rataAbj === null) {
            return ['skor' => null, 'abj_persen' => null, 'confidence' => 'lemah'];
        }

        $skorRisiko = max(0, min(100, 100 - $rataAbj));

        return ['skor' => round($skorRisiko, 2), 'abj_persen' => round($rataAbj, 2), 'confidence' => 'kuat'];
    }

    protected function hitungSkorLaporan(Wilayah $wilayah): float
    {
        $batasWaktu = Carbon::now()->subDays(30);

        $jumlahAktif = LaporanWarga::where('wilayah_kode', $wilayah->kode)
            ->where('created_at', '>=', $batasWaktu)
            ->where('status', '!=', 'selesai')
            ->count();

        return min(100, $jumlahAktif * 20);
    }

    protected function tentukanLevel(float $skor): string
    {
        if ($skor < 40) return 'rendah';
        if ($skor <= 70) return 'sedang';
        return 'tinggi';
    }
}