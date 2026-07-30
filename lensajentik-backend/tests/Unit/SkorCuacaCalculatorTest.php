<?php

namespace Tests\Unit;

use App\Services\SkorCuacaCalculator;
use PHPUnit\Framework\TestCase;

class SkorCuacaCalculatorTest extends TestCase
{
    /* ═══════════════════════════════════════════════════════════════════════
     * fSuhu — Bell curve centered at 27°C
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_f_suhu_puncak_di_27(): void
    {
        $this->assertEquals(100.0, SkorCuacaCalculator::fSuhu(27.0));
    }

    public function test_f_suhu_di_25(): void
    {
        // f_suhu(25) = max(0, 100 - 4*(25-27)^2) = max(0, 100 - 4*4) = max(0, 84) = 84
        $this->assertEquals(84.0, SkorCuacaCalculator::fSuhu(25.0));
    }

    public function test_f_suhu_di_22(): void
    {
        // f_suhu(22) = max(0, 100 - 4*(22-27)^2) = max(0, 100 - 4*25) = 0
        $this->assertEquals(0.0, SkorCuacaCalculator::fSuhu(22.0));
    }

    public function test_f_suhu_di_32(): void
    {
        // f_suhu(32) = max(0, 100 - 4*(32-27)^2) = max(0, 100 - 4*25) = 0
        $this->assertEquals(0.0, SkorCuacaCalculator::fSuhu(32.0));
    }

    public function test_f_suhu_nol_di_batas_jauh(): void
    {
        $this->assertEquals(0.0, SkorCuacaCalculator::fSuhu(18.0));
        $this->assertEquals(0.0, SkorCuacaCalculator::fSuhu(34.0));
        $this->assertEquals(0.0, SkorCuacaCalculator::fSuhu(15.0));
        $this->assertEquals(0.0, SkorCuacaCalculator::fSuhu(40.0));
    }

    public function test_f_suhu_simetris(): void
    {
        // f_suhu(25) = f_suhu(29) karena jarak dari 27 sama (2 derajat)
        $this->assertEquals(
            SkorCuacaCalculator::fSuhu(25.0),
            SkorCuacaCalculator::fSuhu(29.0)
        );

        // f_suhu(24) = f_suhu(30)
        $this->assertEquals(
            SkorCuacaCalculator::fSuhu(24.0),
            SkorCuacaCalculator::fSuhu(30.0)
        );
    }

    public function test_f_suhu_di_30(): void
    {
        // f_suhu(30) = max(0, 100 - 4*(30-27)^2) = max(0, 100 - 4*9) = 64
        $this->assertEquals(64.0, SkorCuacaCalculator::fSuhu(30.0));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * fHujan — Piecewise linear 7-day precipitation
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_f_hujan_nol_pada_kering_total(): void
    {
        $this->assertEquals(0.0, SkorCuacaCalculator::fHujan(0.0));
    }

    public function test_f_hujan_rendah_pada_kering(): void
    {
        // R=5 berada antara (0,0) dan (10,20)
        // y = 0 + (5-0)*(20-0)/(10-0) = 10
        $this->assertEquals(10.0, SkorCuacaCalculator::fHujan(5.0));
    }

    public function test_f_hujan_di_batas_10mm(): void
    {
        $this->assertEquals(20.0, SkorCuacaCalculator::fHujan(10.0));
    }

    public function test_f_hujan_optimal_puncak(): void
    {
        // R=35 berada pada puncak 100
        $this->assertEquals(100.0, SkorCuacaCalculator::fHujan(35.0));
    }

    public function test_f_hujan_optimal_50mm(): void
    {
        // 50mm berada antara (35,100) dan (80,90)
        // y = 100 + (50-35)*(90-100)/(80-35) = 100 + 15*(-10)/45 = 100 - 3.33 = 96.67
        $this->assertEqualsWithDelta(96.67, SkorCuacaCalculator::fHujan(50.0), 0.01);
    }

    public function test_f_hujan_di_80mm(): void
    {
        $this->assertEquals(90.0, SkorCuacaCalculator::fHujan(80.0));
    }

    public function test_f_hujan_di_120mm(): void
    {
        $this->assertEquals(60.0, SkorCuacaCalculator::fHujan(120.0));
    }

    public function test_f_hujan_di_150mm(): void
    {
        $this->assertEquals(30.0, SkorCuacaCalculator::fHujan(150.0));
    }

    public function test_f_hujan_sangat_basah(): void
    {
        // R=180 berada antara (150,30) dan (200,10)
        // y = 30 + (180-150)*(10-30)/(200-150) = 30 + 30*(-20)/50 = 30 - 12 = 18
        $this->assertEqualsWithDelta(18.0, SkorCuacaCalculator::fHujan(180.0), 0.01);
    }

    public function test_f_hujan_di_atas_200mm(): void
    {
        $this->assertEquals(10.0, SkorCuacaCalculator::fHujan(200.0));
        $this->assertEquals(10.0, SkorCuacaCalculator::fHujan(300.0));
    }

    public function test_f_hujan_20mm(): void
    {
        $this->assertEquals(85.0, SkorCuacaCalculator::fHujan(20.0));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * fLembap — Linear from 40% to 100%
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_f_lembap_minimum_40_persen(): void
    {
        $this->assertEquals(0.0, SkorCuacaCalculator::fLembap(40.0));
    }

    public function test_f_lembap_di_bawah_40(): void
    {
        $this->assertEquals(0.0, SkorCuacaCalculator::fLembap(35.0));
        $this->assertEquals(0.0, SkorCuacaCalculator::fLembap(20.0));
        $this->assertEquals(0.0, SkorCuacaCalculator::fLembap(0.0));
    }

    public function test_f_lembap_tengah_70_persen(): void
    {
        // f_lembap(70) = clamp((70-40)/0.6, 0, 100) = 50
        $this->assertEquals(50.0, SkorCuacaCalculator::fLembap(70.0));
    }

    public function test_f_lembap_maksimum_100_persen(): void
    {
        $this->assertEquals(100.0, SkorCuacaCalculator::fLembap(100.0));
    }

    public function test_f_lembap_di_atas_100(): void
    {
        $this->assertEquals(100.0, SkorCuacaCalculator::fLembap(100.0));
        // (105-40)/0.6 = 108.33 → clamp jadi 100
        $this->assertEquals(100.0, SkorCuacaCalculator::fLembap(105.0));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * hitungSkorCuaca — Combined score
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_hitung_skor_cuaca_kondisi_ideal(): void
    {
        // T=27 (fSuhu=100), R=35 (fHujan=100), RH=100 (fLembap=100)
        // skor = 0.4*100 + 0.4*100 + 0.2*100 = 100
        $this->assertEquals(100.0, SkorCuacaCalculator::hitungSkorCuaca(27.0, 35.0, 100.0));
    }

    public function test_hitung_skor_cuaca_kondisi_kering(): void
    {
        // T=27 (fSuhu=100), R=0 (fHujan=0), RH=40 (fLembap=0)
        // skor = 0.4*100 + 0.4*0 + 0.2*0 = 40
        $this->assertEquals(40.0, SkorCuacaCalculator::hitungSkorCuaca(27.0, 0.0, 40.0));
    }

    public function test_hitung_skor_cuaca_kondisi_bogor(): void
    {
        // Bogor typical: T=25 (fSuhu=84), R=50 (fHujan≈96.67), RH=85 (fLembap=75)
        // skor ≈ 0.4*84 + 0.4*96.67 + 0.2*75 = 33.6 + 38.67 + 15 = 87.27
        $skor = SkorCuacaCalculator::hitungSkorCuaca(25.0, 50.0, 85.0);
        $this->assertGreaterThan(80.0, $skor);
        $this->assertLessThan(95.0, $skor);
    }

    public function test_hitung_skor_cuaca_selalu_dalam_rentang(): void
    {
        // Property-based: skor selalu 0–100 untuk input ekstrem
        $testCases = [
            [15.0, 0.0, 20.0],
            [40.0, 300.0, 100.0],
            [27.0, 35.0, 70.0],
            [20.0, 150.0, 50.0],
            [32.0, 10.0, 90.0],
        ];

        foreach ($testCases as [$suhu, $hujan, $rh]) {
            $skor = SkorCuacaCalculator::hitungSkorCuaca($suhu, $hujan, $rh);
            $this->assertGreaterThanOrEqual(0.0, $skor);
            $this->assertLessThanOrEqual(100.0, $skor);
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * terapkanPenaltiElevasi
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_penalti_elevasi_null_tidak_berpengaruh(): void
    {
        $this->assertEquals(80.0, SkorCuacaCalculator::terapkanPenaltiElevasi(80.0, null));
    }

    public function test_penalti_elevasi_di_bawah_1000m_tidak_berpengaruh(): void
    {
        $this->assertEquals(80.0, SkorCuacaCalculator::terapkanPenaltiElevasi(80.0, 500.0));
        $this->assertEquals(80.0, SkorCuacaCalculator::terapkanPenaltiElevasi(80.0, 1000.0));
    }

    public function test_penalti_elevasi_di_1500m(): void
    {
        // faktor = 1 - 0.5*(1500-1000)/1000 = 1 - 0.25 = 0.75
        // skor = 80 * 0.75 = 60
        $this->assertEquals(60.0, SkorCuacaCalculator::terapkanPenaltiElevasi(80.0, 1500.0));
    }

    public function test_penalti_elevasi_maksimum_di_2000m(): void
    {
        // faktor = 1 - 0.5*(2000-1000)/1000 = 1 - 0.5 = 0.5
        // skor = 100 * 0.5 = 50
        $this->assertEquals(50.0, SkorCuacaCalculator::terapkanPenaltiElevasi(100.0, 2000.0));
    }

    public function test_penalti_elevasi_di_atas_2000m_tetap_50_persen(): void
    {
        // Capped at 0.5 factor
        $this->assertEquals(40.0, SkorCuacaCalculator::terapkanPenaltiElevasi(80.0, 3000.0));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * tentukanLevel
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_tentukan_level(): void
    {
        $this->assertSame('rendah', SkorCuacaCalculator::tentukanLevel(0.0));
        $this->assertSame('rendah', SkorCuacaCalculator::tentukanLevel(39.99));
        $this->assertSame('sedang', SkorCuacaCalculator::tentukanLevel(40.0));
        $this->assertSame('sedang', SkorCuacaCalculator::tentukanLevel(70.0));
        $this->assertSame('tinggi', SkorCuacaCalculator::tentukanLevel(70.01));
        $this->assertSame('tinggi', SkorCuacaCalculator::tentukanLevel(100.0));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * hitungRolling7Hari
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_rolling_7hari(): void
    {
        $data = [
            '2026-01-01' => 10.0,
            '2026-01-02' => 10.0,
            '2026-01-03' => 10.0,
            '2026-01-04' => 10.0,
            '2026-01-05' => 10.0,
            '2026-01-06' => 10.0,
            '2026-01-07' => 10.0,
            '2026-01-08' => 5.0,
        ];

        $result = SkorCuacaCalculator::hitungRolling7Hari($data);

        // Hari 1: 10
        $this->assertEquals(10.0, $result['2026-01-01']);
        // Hari 7: 7*10 = 70
        $this->assertEquals(70.0, $result['2026-01-07']);
        // Hari 8: 6*10 + 5 = 65
        $this->assertEquals(65.0, $result['2026-01-08']);
    }
}
