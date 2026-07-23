<?php
namespace App\Console\Commands;

use App\Models\Wilayah;
use App\Services\WeatherService;
use App\Services\RiskScoreService;
use Illuminate\Console\Command;

class RefreshSkorRisiko extends Command
{
    protected $signature = 'skor-risiko:refresh';
    protected $description = 'Hitung ulang skor risiko DBD & Malaria untuk semua wilayah aktif';

    public function handle(WeatherService $weatherService, RiskScoreService $riskScoreService): int
    {
        $this->info('Mulai refresh skor risiko...');

        // "Wilayah aktif" = wilayah yang pernah punya data cuaca (pernah diakses/dicek)
        // ATAU wilayah yang ada kader/admin bertugas di sana
        $wilayahAktif = Wilayah::where('tingkat', 'kecamatan')
            ->where(function ($q) {
                $q->whereHas('dataCuaca')
                  ->orWhereHas('abjLaporan')
                  ->orWhereIn('kode', function ($sub) {
                      $sub->select('wilayah_kode')->from('users')->whereNotNull('wilayah_kode');
                  });
            })
            ->get();

        if ($wilayahAktif->isEmpty()) {
            $this->warn('Tidak ada wilayah aktif untuk di-refresh.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($wilayahAktif->count());
        $bar->start();

        $gagal = 0;

        foreach ($wilayahAktif as $wilayah) {
            try {
                $weatherService->fetchAndCache($wilayah);
                $riskScoreService->hitungDanSimpan($wilayah, 'dbd');
                $riskScoreService->hitungDanSimpan($wilayah, 'malaria');
            } catch (\Exception $e) {
                $gagal++;
                $this->newLine();
                $this->error("Gagal proses {$wilayah->nama}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Selesai. Total: {$wilayahAktif->count()}, Gagal: {$gagal}");

        return self::SUCCESS;
    }
}