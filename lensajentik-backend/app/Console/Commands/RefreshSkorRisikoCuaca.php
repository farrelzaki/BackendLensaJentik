<?php

namespace App\Console\Commands;

use App\Models\Wilayah;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('skor-risiko:refresh-cuaca
    {--wilayah=   : Kode wilayah spesifik (opsional)}
    {--jenis=dbd  : Jenis penyakit (dbd|malaria)}
    {--sync       : Proses langsung tanpa queue (default: pakai queue)}
    {--limit=     : Batasi jumlah wilayah yang diproses (untuk testing)}
    {--provinsi=  : Filter berdasarkan kode provinsi (32 = Jawa Barat)}')]
#[Description('Hitung skor risiko murni cuaca (Open-Meteo). default pakai queue, --sync untuk proses langsung. Gunakan --provinsi=32 untuk Jawa Barat saja.')]
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
                \App\Jobs\HitungSkorRisikoJob::dispatchSync($wilayahOpt, $jenis);
                $this->info('✅ Selesai.');
            } else {
                $this->info("Menghitung skor risiko cuaca untuk: {$wilayah->nama}");
                \App\Jobs\HitungSkorRisikoJob::dispatch($wilayahOpt, $jenis);
                $this->info('✅ Job dispatched.');
            }

            return self::SUCCESS;
        }

        // ── Mode bulk: kecamatan (opsional filter provinsi) ────────────────
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

        if ($sync) {
            // ── Mode sync: jalankan HitungSkorRisikoJob langsung tanpa queue ──
            $this->info("🔵 [sync] Memproses {$kecamatan->count()} kecamatan secara langsung...");
            $bar = $this->output->createProgressBar($kecamatan->count());
            $bar->start();

            $sukses = 0;
            $gagal  = 0;

            foreach ($kecamatan as $wil) {
                try {
                    \App\Jobs\HitungSkorRisikoJob::dispatchSync($wil->kode, $jenis);
                    $sukses++;
                } catch (\Exception $e) {
                    $gagal++;
                    logger()->warning("Gagal proses {$wil->nama}: {$e->getMessage()}");
                }
                $bar->advance();
                // Jeda 200ms untuk hormati rate limit API
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
}
