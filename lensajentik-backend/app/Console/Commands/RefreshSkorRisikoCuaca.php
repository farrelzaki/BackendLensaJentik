<?php

namespace App\Console\Commands;

use App\Models\Wilayah;
use App\Models\SkorRisiko;
use App\Models\PrediksiRisiko;
use App\Services\SkorCuacaCalculator;
use App\Services\WeatherService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('skor-risiko:refresh-cuaca
    {--wilayah=   : Kode wilayah spesifik (opsional)}
    {--jenis=dbd  : Jenis penyakit (dbd|malaria)}
    {--sync       : Proses langsung tanpa queue (default: pakai queue)}
    {--limit=     : Batasi jumlah wilayah yang diproses (untuk testing)}')]
#[Description('Hitung skor risiko murni cuaca (Open-Meteo). default pakai queue, --sync untuk proses langsung.')]
class RefreshSkorRisikoCuaca extends Command
{
    public function handle(): int
    {
        $wilayahOpt = $this->option('wilayah');
        $jenis      = $this->option('jenis') ?: 'dbd';
        $sync       = $this->option('sync');
        $limit      = $this->option('limit') ? (int) $this->option('limit') : null;

        // ── Mode spesifik: satu wilayah ────────────────────────────────────
        if ($wilayahOpt) {
            $wilayah = Wilayah::find($wilayahOpt);

            if (!$wilayah) {
                $this->error("Wilayah dengan kode '{$wilayahOpt}' tidak ditemukan.");
                return self::FAILURE;
            }

            if ($sync) {
                $this->info("🔵 [sync] Menghitung skor untuk: {$wilayah->nama}");
                $this->prosesWilayah($wilayah, $jenis);
                $this->info('✅ Selesai.');
            } else {
                $this->info("Menghitung skor risiko cuaca untuk: {$wilayah->nama}");
                \App\Jobs\HitungSkorRisikoJob::dispatch($wilayahOpt, $jenis);
                $this->info('✅ Job dispatched.');
            }

            return self::SUCCESS;
        }

        // ── Mode bulk: semua kecamatan ─────────────────────────────────────
        $query = Wilayah::where('tingkat', 'kecamatan');

        if ($limit) {
            $query->limit($limit);
        }

        $kecamatan = $query->get();

        if ($kecamatan->isEmpty()) {
            $this->warn('⚠ Tidak ada kecamatan ditemukan. Jalankan WilayahSeeder dulu.');
            return self::FAILURE;
        }

        if ($sync) {
            // ── Mode sync: proses langsung ─────────────────────────────────
            $this->info("🔵 [sync] Memproses {$kecamatan->count()} kecamatan secara langsung...");
            $bar = $this->output->createProgressBar($kecamatan->count());
            $bar->start();

            $sukses = 0;
            $gagal  = 0;

            foreach ($kecamatan as $wil) {
                try {
                    $this->prosesWilayah($wil, $jenis);
                    $sukses++;
                } catch (\Exception $e) {
                    $gagal++;
                    logger()->warning("Gagal proses {$wil->nama}: {$e->getMessage()}");
                }
                $bar->advance();
                // Jeda 200ms untuk hormati rate limit Nominatim (1 req/detik)
                // + Open-Meteo juga punya rate limit
                usleep(200_000);
            }

            $bar->finish();
            $this->newLine();
            $this->info("✅ {$sukses} berhasil, {$gagal} gagal dari {$kecamatan->count()} kecamatan.");

        } else {
            // ── Mode queue: dispatch job ────────────────────────────────────
            $this->info("Mengantrikan HitungSkorRisikoJob untuk {$kecamatan->count()} kecamatan...");
            $bar = $this->output->createProgressBar($kecamatan->count());
            $bar->start();

            $count = 0;
            foreach ($kecamatan as $wil) {
                \App\Jobs\HitungSkorRisikoJob::dispatch($wil->kode, $jenis);
                $count++;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info("✅ {$count} job di-dispatch ke queue. Jalankan 'php artisan queue:work' untuk memproses.");
        }

        return self::SUCCESS;
    }

    /**
     * Proses satu wilayah secara langsung (sync).
     * Geocode → fetch cuaca → hitung skor → simpan.
     */
    protected function prosesWilayah(Wilayah $wilayah, string $jenis): void
    {
        $weatherService = app(WeatherService::class);

        // 1. Pastikan koordinat ada (geocode jika perlu)
        if (!$wilayah->latitude || !$wilayah->longitude) {
            $ok = $weatherService->ensureCoordinates($wilayah);
            if (!$ok) {
                $this->warn("  ⚠ Gagal geocode: {$wilayah->nama}, skip.");
                return;
            }
            $wilayah->refresh();
        }

        // 2. Fetch data cuaca full-range (14 hari historis + 16 hari forecast)
        $dataCuaca = $weatherService->fetchFullRange($wilayah);

        if (empty($dataCuaca)) {
            $this->warn("  ⚠ Gagal fetch cuaca untuk {$wilayah->nama}, skip.");
            return;
        }

        // Urutkan berdasarkan tanggal
        usort($dataCuaca, fn($a, $b) => $a->tanggal <=> $b->tanggal);

        // 3. Hitung rolling 7-day precipitation
        $hujanPerTanggal = [];
        foreach ($dataCuaca as $cuaca) {
            $hujanPerTanggal[$cuaca->tanggal->toDateString()] = (float) ($cuaca->curah_hujan ?? 0);
        }
        $rolling7Hari = SkorCuacaCalculator::hitungRolling7Hari($hujanPerTanggal);

        // 4. Hitung skor per tanggal
        $today = Carbon::today('Asia/Jakarta');
        $tanggalPerhitungan = $today->toDateString();
        $elevasi = $wilayah->elevasi !== null ? (float) $wilayah->elevasi : null;

        foreach ($dataCuaca as $cuaca) {
            $tanggalStr = $cuaca->tanggal->toDateString();
            $isForecast = $tanggalStr > $today->toDateString();

            $suhu   = $cuaca->suhu_avg ?? 27.0;
            $hujan7 = $rolling7Hari[$tanggalStr] ?? 0.0;
            $lembap = $cuaca->kelembapan_avg ?? 60.0;

            $skorCuaca = SkorCuacaCalculator::hitungSkorCuaca(
                (float) $suhu,
                (float) $hujan7,
                (float) $lembap,
            );

            $skorFinal = SkorCuacaCalculator::terapkanPenaltiElevasi($skorCuaca, $elevasi);
            $level = SkorCuacaCalculator::tentukanLevel($skorFinal);

            $faktor = [
                'skor_cuaca'           => $skorCuaca,
                'f_suhu'               => SkorCuacaCalculator::fSuhu((float) $suhu),
                'f_hujan'              => SkorCuacaCalculator::fHujan((float) $hujan7),
                'f_lembap'             => SkorCuacaCalculator::fLembap((float) $lembap),
                'suhu'                 => (float) $suhu,
                'akumulasi_hujan_7hari' => (float) $hujan7,
                'kelembapan'           => (float) $lembap,
                'elevasi'              => $elevasi,
                'penalti_elevasi'      => $skorFinal !== $skorCuaca,
            ];

            if ($isForecast) {
                PrediksiRisiko::updateOrCreate(
                    [
                        'wilayah_kode'       => $wilayah->kode,
                        'jenis_penyakit'     => $jenis,
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
                SkorRisiko::updateOrCreate(
                    [
                        'wilayah_kode'   => $wilayah->kode,
                        'jenis_penyakit' => $jenis,
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
    }
}
