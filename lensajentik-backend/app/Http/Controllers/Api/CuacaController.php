<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use App\Models\DataCuaca;
use App\Services\WeatherService;
use Carbon\Carbon;

class CuacaController extends Controller
{
    public function __construct(protected WeatherService $weatherService) {}

    /**
     * GET /api/cuaca/{kode_wilayah}
     *
     * Ambil data cuaca untuk 1 wilayah (cache-first, auto-refresh kalau data basi >6 jam).
     *
     * Untuk kecamatan:
     *   - Fetch & cache dari Open-Meteo API
     *   - Return data langsung dari data_cuaca
     *
     * Untuk kabupaten/kota & provinsi:
     *   - Agregasi rata-rata dari seluruh data_cuaca kecamatan anak
     *   - Data tidak akan kosong selama ada data cuaca di level kecamatan
     */
    public function show(string $kode)
    {
        $wilayah = Wilayah::find($kode);

        if (!$wilayah) {
            return response()->json(['message' => 'Wilayah tidak ditemukan'], 404);
        }

        // ── Level kecamatan/desa: fetch & cache langsung ──────────────
        if (!$wilayah->isAdministrativeLevel()) {
            $target = $wilayah;
            if ($wilayah->tingkat === 'desa') {
                $target = Wilayah::where('kode', $wilayah->parent_kode)
                    ->where('tingkat', 'kecamatan')
                    ->first();
                if (!$target) {
                    return response()->json(['message' => 'Parent kecamatan tidak ditemukan'], 404);
                }
            }

            $today = Carbon::today('Asia/Jakarta')->toDateString();

            $dataHariIni = DataCuaca::where('wilayah_kode', $target->kode)
                ->where('tanggal', $today)
                ->where('is_forecast', false)
                ->first();

            // Kalau belum ada data hari ini, atau data terakhir di-update >6 jam lalu, refresh
            $perluRefresh = !$dataHariIni || $dataHariIni->updated_at->diffInHours(now()) >= 6;

            if ($perluRefresh) {
                $sukses = $this->weatherService->fetchAndCache($target);

                if (!$sukses && !$dataHariIni) {
                    return response()->json(['message' => 'Gagal mengambil data cuaca dan tidak ada cache tersedia'], 502);
                }
            }

            $historis = DataCuaca::where('wilayah_kode', $target->kode)
                ->where('is_forecast', false)
                ->orderByDesc('tanggal')
                ->limit(1)
                ->get();

            $forecast = DataCuaca::where('wilayah_kode', $target->kode)
                ->where('is_forecast', true)
                ->orderBy('tanggal')
                ->get();

            return response()->json([
                'wilayah'         => $target->only(['kode', 'nama', 'tingkat', 'latitude', 'longitude', 'elevasi']),
                'hari_ini'        => $historis->first(),
                'forecast_14_hari'=> $forecast,
            ]);
        }

        // ── Level kabupaten/provinsi: AGREGASI dari kecamatan ─────────
        $kecamatanCodes = $wilayah->getAllKecamatanCodes();

        if (empty($kecamatanCodes)) {
            return response()->json([
                'wilayah'         => $wilayah->only(['kode', 'nama', 'tingkat', 'latitude', 'longitude', 'elevasi']),
                'hari_ini'        => null,
                'forecast_14_hari'=> [],
                'message'         => 'Tidak ada data kecamatan di wilayah ini.',
            ]);
        }

        // Agregasi data cuaca per tanggal dari seluruh kecamatan
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        // Historis: semua record non-forecast, diagregasi per tanggal
        $historisRaw = DataCuaca::whereIn('wilayah_kode', $kecamatanCodes)
            ->where('is_forecast', false)
            ->orderByDesc('tanggal')
            ->get()
            ->groupBy('tanggal');

        $historis = $historisRaw->map(function ($items, $tanggal) use ($kecamatanCodes) {
            return [
                'tanggal'              => $tanggal,
                'suhu_avg'             => round($items->avg('suhu_avg'), 1),
                'kelembapan_avg'       => round($items->avg('kelembapan_avg'), 1),
                'curah_hujan_rata'     => round($items->avg('curah_hujan'), 2),
                'curah_hujan_total'    => round($items->sum('curah_hujan'), 2),
                'jumlah_kecamatan'     => count($kecamatanCodes),
                'kecamatan_dengan_data'=> $items->count(),
                'is_agregat'           => true,
                'sumber_api'           => 'open-meteo (agregat kecamatan)',
            ];
        })->values();

        // Forecast: semua record is_forecast=true, diagregasi per tanggal
        $forecastRaw = DataCuaca::whereIn('wilayah_kode', $kecamatanCodes)
            ->where('is_forecast', true)
            ->orderBy('tanggal')
            ->get()
            ->groupBy('tanggal');

        $forecast = $forecastRaw->map(function ($items, $tanggal) use ($kecamatanCodes) {
            return [
                'tanggal'              => $tanggal,
                'suhu_avg'             => round($items->avg('suhu_avg'), 1),
                'kelembapan_avg'       => round($items->avg('kelembapan_avg'), 1),
                'curah_hujan_rata'     => round($items->avg('curah_hujan'), 2),
                'curah_hujan_total'    => round($items->sum('curah_hujan'), 2),
                'jumlah_kecamatan'     => count($kecamatanCodes),
                'kecamatan_dengan_data'=> $items->count(),
                'is_agregat'           => true,
                'sumber_api'           => 'open-meteo (agregat kecamatan)',
            ];
        })->values();

        // Hari ini (agregat)
        $hariIni = $historis->firstWhere('tanggal', $today);

        return response()->json([
            'wilayah'          => $wilayah->only(['kode', 'nama', 'tingkat', 'latitude', 'longitude', 'elevasi']),
            'jumlah_kecamatan' => count($kecamatanCodes),
            'hari_ini'         => $hariIni ?? $historis->first(),
            'historis_30_hari' => $historis,
            'forecast_14_hari' => $forecast,
            'metode'           => 'agregasi rata-rata dari data cuaca seluruh kecamatan',
        ]);
    }
}
