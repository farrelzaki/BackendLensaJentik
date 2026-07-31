<?php

namespace App\Console\Commands;

use App\Models\Wilayah;
use Illuminate\Console\Command;

/**
 * Bersihkan koordinat wilayah yang jelas-jelas salah (di luar Indonesia),
 * lalu re-geocode ulang.
 *
 * Batas geografis Indonesia:
 *   Latitude:  -11° s/d 6° (selatan ke utara)
 *   Longitude: 95° s/d 141° (barat ke timur)
 */
class FixWilayahCoordinates extends Command
{
    protected $signature = 'wilayah:fix-coordinates
                            {--dry-run : Hanya cek, jangan ubah apa-apa}
                            {--regeocode : Setelah bersihkan, coba geocode ulang}';

    protected $description = 'Bersihkan koordinat kabupaten/kecamatan yang salah (di luar Indonesia)';

    public function handle(): int
    {
        // Batas Indonesia
        $minLat = -11.0; $maxLat = 6.0;
        $minLng = 95.0;  $maxLng = 141.0;

        // ── Kabupaten ────────────────────────────────────────────────────
        $kabBad = Wilayah::where('tingkat', 'kabupaten')
            ->where(function ($q) use ($minLat, $maxLat, $minLng, $maxLng) {
                $q->whereNull('latitude')
                  ->orWhereNull('longitude')
                  ->orWhere('latitude', '<', $minLat)
                  ->orWhere('latitude', '>', $maxLat)
                  ->orWhere('longitude', '<', $minLng)
                  ->orWhere('longitude', '>', $maxLng);
            })
            ->get();

        $this->info("Kabupaten dengan koordinat salah: {$kabBad->count()}");

        foreach ($kabBad as $kab) {
            $this->line("  {$kab->kode} {$kab->nama} — lat={$kab->latitude} lng={$kab->longitude}");

            if (!$this->option('dry-run')) {
                $kab->update(['latitude' => null, 'longitude' => null]);
            }
        }

        // ── Kecamatan ────────────────────────────────────────────────────
        $kecBad = Wilayah::where('tingkat', 'kecamatan')
            ->where(function ($q) use ($minLat, $maxLat, $minLng, $maxLng) {
                $q->whereNull('latitude')
                  ->orWhereNull('longitude')
                  ->orWhere('latitude', '<', $minLat)
                  ->orWhere('latitude', '>', $maxLat)
                  ->orWhere('longitude', '<', $minLng)
                  ->orWhere('longitude', '>', $maxLng);
            })
            ->count();

        $this->info("Kecamatan dengan koordinat salah: {$kecBad}");

        if (!$this->option('dry-run')) {
            Wilayah::where('tingkat', 'kecamatan')
                ->where(function ($q) use ($minLat, $maxLat, $minLng, $maxLng) {
                    $q->whereNull('latitude')
                      ->orWhereNull('longitude')
                      ->orWhere('latitude', '<', $minLat)
                      ->orWhere('latitude', '>', $maxLat)
                      ->orWhere('longitude', '<', $minLng)
                      ->orWhere('longitude', '>', $maxLng);
                })
                ->update(['latitude' => null, 'longitude' => null]);

            $this->info('✅ Koordinat salah sudah dibersihkan.');
            $this->info('   Sistem akan otomatis re-geocode saat wilayah diakses.');
        } else {
            $this->info('🔍 Dry run — tidak ada perubahan.');
        }

        return self::SUCCESS;
    }
}
