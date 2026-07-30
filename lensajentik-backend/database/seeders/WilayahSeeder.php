<?php
namespace Database\Seeders;

use App\Models\Wilayah;
use App\Services\WeatherService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

class WilayahSeeder extends Seeder
{
    protected string $baseUrl = 'https://emsifa.github.io/api-wilayah-indonesia/api';

    public function run(): void
    {
        $weatherService = app(WeatherService::class);
        $provinces = $this->fetch("{$this->baseUrl}/provinces.json");

        foreach ($provinces as $prov) {
            Wilayah::updateOrCreate(['kode' => $prov['id']], [
                'nama' => $prov['name'],
                'tingkat' => 'provinsi',
                'parent_kode' => null,
            ]);
            $this->command->info("Provinsi: {$prov['name']}");

            $regencies = $this->fetch("{$this->baseUrl}/regencies/{$prov['id']}.json");

            foreach ($regencies as $reg) {
                Wilayah::updateOrCreate(['kode' => $reg['id']], [
                    'nama' => $reg['name'],
                    'tingkat' => 'kabupaten',
                    'parent_kode' => $prov['id'],
                ]);

                $districts = $this->fetch("{$this->baseUrl}/districts/{$reg['id']}.json");

                foreach ($districts as $dist) {
                    $wilayah = Wilayah::updateOrCreate(['kode' => $dist['id']], [
                        'nama' => $dist['name'],
                        'tingkat' => 'kecamatan',
                        'parent_kode' => $reg['id'],
                    ]);

                    // Fetch elevasi untuk kecamatan — hanya jika belum ada
                    if ($wilayah->elevasi === null && $wilayah->latitude && $wilayah->longitude) {
                        try {
                            $weatherService->fetchDanSimpanElevasi($wilayah);
                        } catch (\Exception $e) {
                            // Elevasi opsional — jangan hentikan seeding
                        }
                    }
                }
                $this->command->info("  Kabupaten: {$reg['name']} (+" . count($districts) . " kecamatan)");
            }
        }
    }

    /**
     * Fetch dengan retry otomatis, dan selalu return array (gak pernah null).
     */
    protected function fetch(string $url): array
    {
        try {
            $response = Http::retry(3, 2000)->timeout(30)->get($url);
            $data = $response->json();

            if (!is_array($data)) {
                $this->command->warn("  ⚠ Response kosong/invalid dari: {$url}");
                return [];
            }

            return $data;
        } catch (\Exception $e) {
            $this->command->error("  ✗ Gagal fetch {$url}: {$e->getMessage()}");
            return [];
        }
    }
}