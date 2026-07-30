<?php
namespace App\Services;

use App\Models\Wilayah;
use App\Models\DataCuaca;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class WeatherService
{
    protected string $geocodingUrl = 'https://geocoding-api.open-meteo.com/v1/search';
    protected string $forecastUrl = 'https://api.open-meteo.com/v1/forecast';
    protected string $elevationUrl = 'https://api.open-meteo.com/v1/elevation';

    /**
     * Pastikan wilayah punya koordinat. Kalau belum, geocode dan simpan.
     */
    public function ensureCoordinates(Wilayah $wilayah): bool
    {
        if ($wilayah->latitude && $wilayah->longitude) {
            return true;
        }

        // Susun nama pencarian: nama wilayah + ", Indonesia" biar hasil lebih akurat
        $namaParent = optional($wilayah->parent)->nama;
        $query = $namaParent ? "{$wilayah->nama}, {$namaParent}, Indonesia" : "{$wilayah->nama}, Indonesia";

        $response = Http::retry(2, 1000)->timeout(15)->get($this->geocodingUrl, [
            'name' => $wilayah->nama,
            'count' => 5,
            'language' => 'id',
            'country' => 'ID',
        ]);

        $results = $response->json('results');

        if (empty($results)) {
            return false;
        }

        // Ambil hasil pertama yang match
        $best = $results[0];

        $wilayah->update([
            'latitude' => $best['latitude'],
            'longitude' => $best['longitude'],
        ]);

        return true;
    }

    /**
     * Fetch cuaca (historis hari ini + forecast 14 hari) dan simpan ke data_cuaca.
     * Return true kalau berhasil.
     */
    public function fetchAndCache(Wilayah $wilayah): bool
    {
        if (!$this->ensureCoordinates($wilayah)) {
            return false;
        }

        $response = Http::retry(2, 1000)->timeout(15)->get($this->forecastUrl, [
            'latitude' => $wilayah->latitude,
            'longitude' => $wilayah->longitude,
            'daily' => 'temperature_2m_mean,relative_humidity_2m_mean,precipitation_sum',
            'timezone' => 'Asia/Jakarta',
            'forecast_days' => 14,
            'past_days' => 1,
        ]);

        if (!$response->successful()) {
            return false;
        }

        $daily = $response->json('daily');

        if (!$daily || !isset($daily['time'])) {
            return false;
        }

        $today = Carbon::today('Asia/Jakarta')->toDateString();

        foreach ($daily['time'] as $i => $tanggal) {
            DataCuaca::updateOrCreate(
                [
                    'wilayah_kode' => $wilayah->kode,
                    'tanggal' => $tanggal,
                    'is_forecast' => $tanggal > $today,
                ],
                [
                    'suhu_avg' => $daily['temperature_2m_mean'][$i] ?? null,
                    'kelembapan_avg' => $daily['relative_humidity_2m_mean'][$i] ?? null,
                    'curah_hujan' => $daily['precipitation_sum'][$i] ?? null,
                    'sumber_api' => 'open-meteo',
                ]
            );
        }

        return true;
    }

    /**
     * Fetch cuaca full-range: 14 hari historis + 16 hari forecast dalam SATU request.
     * Total 30 hari data cuaca.
     *
     * @return DataCuaca[] Array model DataCuaca yang berhasil di-fetch/disimpan
     */
    public function fetchFullRange(Wilayah $wilayah): array
    {
        if (!$this->ensureCoordinates($wilayah)) {
            return [];
        }

        $response = Http::retry(2, 1000)->timeout(15)->get($this->forecastUrl, [
            'latitude'  => $wilayah->latitude,
            'longitude' => $wilayah->longitude,
            'daily'     => 'temperature_2m_mean,relative_humidity_2m_mean,precipitation_sum',
            'timezone'  => 'Asia/Jakarta',
            'past_days'   => 14,
            'forecast_days' => 16,
        ]);

        if (!$response->successful()) {
            return [];
        }

        $daily = $response->json('daily');

        if (!$daily || !isset($daily['time'])) {
            return [];
        }

        $today = Carbon::today('Asia/Jakarta')->toDateString();
        $records = [];

        foreach ($daily['time'] as $i => $tanggal) {
            $records[] = DataCuaca::updateOrCreate(
                [
                    'wilayah_kode' => $wilayah->kode,
                    'tanggal'      => $tanggal,
                    'is_forecast'  => $tanggal > $today,
                ],
                [
                    'suhu_avg'       => $daily['temperature_2m_mean'][$i] ?? null,
                    'kelembapan_avg' => $daily['relative_humidity_2m_mean'][$i] ?? null,
                    'curah_hujan'    => $daily['precipitation_sum'][$i] ?? null,
                    'sumber_api'     => 'open-meteo',
                ]
            );
        }

        return $records;
    }

    /**
     * Fetch elevasi wilayah dari Open-Meteo Elevation API.
     * Hanya perlu dipanggil SEKALI per wilayah (saat seeding).
     *
     * GET https://api.open-meteo.com/v1/elevation?latitude=..&longitude=..
     *
     * @return float|null Elevasi dalam meter, atau null jika gagal
     */
    public function fetchDanSimpanElevasi(Wilayah $wilayah): ?float
    {
        // Elevasi sudah ada, skip
        if ($wilayah->elevasi !== null) {
            return (float) $wilayah->elevasi;
        }

        if (!$this->ensureCoordinates($wilayah)) {
            return null;
        }

        $response = Http::retry(2, 1000)->timeout(15)->get($this->elevationUrl, [
            'latitude'  => $wilayah->latitude,
            'longitude' => $wilayah->longitude,
        ]);

        if (!$response->successful()) {
            return null;
        }

        $elevation = $response->json('elevation');

        if ($elevation === null || !is_numeric($elevation)) {
            return null;
        }

        $elevasi = round((float) $elevation[0], 2);

        $wilayah->update(['elevasi' => $elevasi]);

        return $elevasi;
    }
}