<?php

namespace App\Services;

/**
 * Kalkulator skor risiko berbasis murni cuaca — tanpa data lapangan.
 *
 * Semua method bersifat static dan pure-function:
 * tidak ada dependency eksternal, mudah di-unit-test.
 *
 * Formula utama:
 *   skor_cuaca = 0.4 * fSuhu(T) + 0.4 * fHujan(R_7hari) + 0.2 * fLembap(RH)
 */
class SkorCuacaCalculator
{
    /**
     * f_suhu(T) — bell curve dengan puncak di 27°C.
     *
     *   f_suhu = max(0, 100 - 4 * (T - 27)^2)
     *
     * T=27 → 100 | T=22 atau T=32 → 0 | T < 22 atau T > 32 → 0
     *
     * @param float $suhuCelsius Suhu rata-rata harian (°C)
     */
    public static function fSuhu(float $suhuCelsius): float
    {
        $deviasi = $suhuCelsius - 27.0;
        $skor = 100.0 - 4.0 * ($deviasi * $deviasi);

        return max(0.0, $skor);
    }

    /**
     * f_hujan(R_7hari) — piecewise linear interpolation dari akumulasi
     * curah hujan 7-hari berjalan (mm).
     *
     * Breakpoints (x = R_7hari dalam mm, y = skor 0–100):
     *   (0, 0)    → kering total
     *   (10, 20)  → mulai ada genangan
     *   (20, 85)  → optimal untuk perkembangbiakan nyamuk
     *   (35, 100) → puncak optimal
     *   (80, 90)  → mulai terlalu basah
     *   (120, 60) → banyak larva tersapu
     *   (150, 30) → banjir ringan
     *   (200, 10) → banjir besar, larva hanyut
     *
     * @param float $akumulasi7HariMm Total curah hujan 7 hari terakhir (mm)
     */
    public static function fHujan(float $akumulasi7HariMm): float
    {
        $r = $akumulasi7HariMm;

        // Di luar rentang breakpoints
        if ($r <= 0.0) return 0.0;
        if ($r >= 200.0) return 10.0;

        // Piecewise linear interpolation
        $breakpoints = [
            [0.0, 0.0],
            [10.0, 20.0],
            [20.0, 85.0],
            [35.0, 100.0],
            [80.0, 90.0],
            [120.0, 60.0],
            [150.0, 30.0],
            [200.0, 10.0],
        ];

        // Cari segmen tempat r berada
        for ($i = 0; $i < count($breakpoints) - 1; $i++) {
            [$x1, $y1] = $breakpoints[$i];
            [$x2, $y2] = $breakpoints[$i + 1];

            if ($r >= $x1 && $r <= $x2) {
                // Linear interpolation: y = y1 + (r - x1) * (y2 - y1) / (x2 - x1)
                if ($x2 == $x1) return $y1;
                return $y1 + ($r - $x1) * ($y2 - $y1) / ($x2 - $x1);
            }
        }

        return 0.0; // fallback
    }

    /**
     * f_lembap(RH) — linear dari 40% ke 100%.
     *
     *   f_lembap = clamp((RH - 40) / 0.6, 0, 100)
     *
     * RH=40 → 0 | RH=70 → 50 | RH=100 → 100
     *
     * @param float $kelembapanPersen Relative humidity (%)
     */
    public static function fLembap(float $kelembapanPersen): float
    {
        $skor = ($kelembapanPersen - 40.0) / 0.6;

        return max(0.0, min(100.0, $skor));
    }

    /**
     * Hitung skor cuaca gabungan.
     *
     *   skor_cuaca = 0.4 * fSuhu(T) + 0.4 * fHujan(R_7hari) + 0.2 * fLembap(RH)
     *
     * @param float $suhu          Suhu rata-rata harian (°C)
     * @param float $hujan7Hari    Akumulasi curah hujan 7 hari terakhir (mm)
     * @param float $kelembapan    Relative humidity (%)
     */
    public static function hitungSkorCuaca(
        float $suhu,
        float $hujan7Hari,
        float $kelembapan
    ): float {
        $skor = 0.4 * self::fSuhu($suhu)
              + 0.4 * self::fHujan($hujan7Hari)
              + 0.2 * self::fLembap($kelembapan);

        return max(0.0, min(100.0, round($skor, 2)));
    }

    /**
     * Terapkan penalti elevasi. Aedes aegypti jarang bertahan di dataran tinggi.
     *
     * Tanpa penalti (faktor 1.0) untuk elevasi <= 1000 mdpl.
     * Penalti linear dari 1.0 → 0.5 untuk elevasi 1000 → 2000 mdpl.
     * Faktor minimum 0.5 (skor tidak akan dikurangi lebih dari 50%).
     *
     * @param float      $skor         Skor awal (0–100)
     * @param float|null $elevasiMdpl  Elevasi dalam meter di atas permukaan laut
     */
    public static function terapkanPenaltiElevasi(float $skor, ?float $elevasiMdpl): float
    {
        if ($elevasiMdpl === null || $elevasiMdpl <= 1000.0) {
            return $skor;
        }

        // Linear dari 1.0 (1000m) ke 0.5 (2000m)
        $faktor = 1.0 - 0.5 * min(($elevasiMdpl - 1000.0) / 1000.0, 1.0);

        return round($skor * $faktor, 2);
    }

    /**
     * Tentukan level risiko dari skor numerik.
     *
     * @param float $skor 0–100
     */
    public static function tentukanLevel(float $skor): string
    {
        if ($skor < 40.0) return 'rendah';
        if ($skor <= 70.0) return 'sedang';
        return 'tinggi';
    }

    /**
     * Hitung rolling sum curah hujan 7-hari untuk setiap tanggal.
     *
     * Input: array [tanggal => curah_hujan] berurutan.
     * Output: array [tanggal => akumulasi_7hari] (hanya untuk tanggal yg memiliki
     * cukup data historis).
     *
     * @param array<string, float> $hujanPerTanggal key=tanggal, value=curah_hujan
     * @return array<string, float>
     */
    public static function hitungRolling7Hari(array $hujanPerTanggal): array
    {
        $tanggal = array_keys($hujanPerTanggal);
        $nilai = array_values($hujanPerTanggal);
        $n = count($tanggal);
        $hasil = [];

        for ($i = 0; $i < $n; $i++) {
            // Ambil maksimal 7 hari: hari ini + 6 hari sebelumnya
            $akumulasi = 0.0;
            $start = max(0, $i - 6);
            for ($j = $start; $j <= $i; $j++) {
                $akumulasi += $nilai[$j];
            }
            $hasil[$tanggal[$i]] = round($akumulasi, 2);
        }

        return $hasil;
    }
}
