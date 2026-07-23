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
}