<?php

namespace App\Console\Commands;

use App\Models\Wilayah;
use App\Services\RiskScoreService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('skor-risiko:refresh-cuaca
    {--wilayah=   : Kode wilayah spesifik (opsional)}
    {--jenis=dbd  : Jenis penyakit (dbd|malaria)}
    {--limit=     : Batasi jumlah wilayah yang diproses (untuk testing)}
    {--provinsi=  : Filter berdasarkan kode provinsi (32 = Jawa Barat)}')]
#[Description('Hitung skor risiko murni cuaca (Open-Meteo) — proses sinkron tanpa queue. Gunakan --provinsi=32 untuk Jawa Barat saja.')]
class RefreshSkorRisikoCuaca extends Command
{
    public function handle(RiskScoreService $riskScoreService): int
    {
        $wilayahOpt = $this->option('wilayah');
        $jenis      = $this->option('jenis') ?: 'dbd';
        $limit      = $this->option('limit') ? (int) $this->option('limit') : null;

        // ── Mode spesifik: satu wilayah ────────────────────────────────
        if ($wilayahOpt) {
            $wilayah = Wilayah::find($wilayahOpt);

            if (! $wilayah) {
                $this->error("Wilayah dengan kode '{$wilayahOpt}' tidak ditemukan.");
                return self::FAILURE;
            }

            $this->info("🔵 Menghitung skor untuk: {$wilayah->nama} ({$jenis})");
            $riskScoreService->hitungDanSimpan($wilayah, $jenis, paksaRefresh: true);
            $this->info('✅ Selesai.');

            return self::SUCCESS;
        }

        // ── Mode bulk: semua kecamatan, proses sinkron ──────────────────
        $query = Wilayah::where('tingkat', 'kecamatan');

        // Filter by provinsi: cari kecamatan yang parent kabupaten-nya
        // berada di bawah provinsi tertentu (mis. 32 = Jawa Barat)
        if ($provinsi = $this->option('provinsi')) {
            $query->whereIn('parent_kode', function ($sub) use ($provinsi) {
                $sub->select('kode')
                    ->from('wilayah')
                    ->where('parent_kode', $provinsi)
                    ->where('tingkat', 'kabupaten');
            });
        }

        if ($limit) {
            $query->limit($limit);
        }

        $kecamatan = $query->get();

        if ($kecamatan->isEmpty()) {
            $this->warn('⚠ Tidak ada kecamatan ditemukan. Jalankan WilayahSeeder dulu.');
            return self::FAILURE;
        }

        $this->info("🔵 Memproses {$kecamatan->count()} kecamatan secara sinkron ({$jenis})...");
        $bar = $this->output->createProgressBar($kecamatan->count());
        $bar->start();

        $sukses = 0;
        $gagal  = 0;

        foreach ($kecamatan as $wil) {
            try {
                // paksaRefresh=true → fetch data cuaca terbaru dari Open-Meteo
                // lalu hitung & simpan skor risiko
                $riskScoreService->hitungDanSimpan($wil, $jenis, paksaRefresh: true);
                $sukses++;
            } catch (\Exception $e) {
                $gagal++;
                logger()->warning("RefreshSkorRisikoCuaca: Gagal proses {$wil->nama}: {$e->getMessage()}");
            }
            $bar->advance();
            // Jeda 200ms untuk hormati rate limit Open-Meteo API (max 600 req/min)
            usleep(200_000);
        }

        $bar->finish();
        $this->newLine();
        $this->info("✅ {$sukses} berhasil, {$gagal} gagal dari {$kecamatan->count()} kecamatan.");

        return self::SUCCESS;
    }
}
