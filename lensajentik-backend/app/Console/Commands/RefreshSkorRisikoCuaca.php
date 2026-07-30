<?php

namespace App\Console\Commands;

use App\Jobs\HitungSkorRisikoJob;
use App\Models\Wilayah;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('skor-risiko:refresh-cuaca
    {--wilayah=   : Kode wilayah kecamatan spesifik (opsional)}
    {--jenis=dbd  : Jenis penyakit (dbd|malaria)}')]
#[Description('Hitung skor risiko murni cuaca (Open-Meteo) via HitungSkorRisikoJob. Confidence level lemah untuk semua.')]
class RefreshSkorRisikoCuaca extends Command
{
    public function handle(): int
    {
        $wilayahOpt = $this->option('wilayah');
        $jenis = $this->option('jenis') ?: 'dbd';

        // ── Mode spesifik: satu kecamatan ──────────────────────────────────
        if ($wilayahOpt) {
            $wilayah = Wilayah::find($wilayahOpt);

            if (!$wilayah) {
                $this->error("Wilayah dengan kode '{$wilayahOpt}' tidak ditemukan.");
                return self::FAILURE;
            }

            $this->info("Menghitung skor risiko cuaca untuk: {$wilayah->nama}");
            HitungSkorRisikoJob::dispatch($wilayahOpt, $jenis);
            $this->info('✅ Job dispatched.');

            return self::SUCCESS;
        }

        // ── Mode bulk: semua kecamatan aktif (koordinat akan diambil otomatis jika belum ada) ──
        $query = Wilayah::where('tingkat', 'kecamatan');

        if ($this->option('wilayah')) {
            $query->where('kode', $this->option('wilayah'));
        }

        $kecamatan = $query->get();

        if ($kecamatan->isEmpty()) {
            $this->warn('⚠ Tidak ada kecamatan ditemukan. Jalankan WilayahSeeder dulu.');
            return self::FAILURE;
        }

        $this->info("Mengantrikan HitungSkorRisikoJob untuk {$kecamatan->count()} kecamatan...");
        $bar = $this->output->createProgressBar($kecamatan->count());
        $bar->start();

        $count = 0;
        foreach ($kecamatan as $wil) {
            HitungSkorRisikoJob::dispatch($wil->kode, $jenis);
            $count++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✅ {$count} job di-dispatch ke queue.");

        return self::SUCCESS;
    }
}
