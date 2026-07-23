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
     * Ambil data cuaca 1 wilayah (cache-first, auto-refresh kalau data basi >6 jam)
     */
    public function show(string $kode)
    {
        $wilayah = Wilayah::find($kode);

        if (!$wilayah) {
            return response()->json(['message' => 'Wilayah tidak ditemukan'], 404);
        }

        $today = Carbon::today('Asia/Jakarta')->toDateString();

        $dataHariIni = DataCuaca::where('wilayah_kode', $kode)
            ->where('tanggal', $today)
            ->where('is_forecast', false)
            ->first();

        // Kalau belum ada data hari ini, atau data terakhir di-update >6 jam lalu, refresh
        $perluRefresh = !$dataHariIni || $dataHariIni->updated_at->diffInHours(now()) >= 6;

        if ($perluRefresh) {
            $sukses = $this->weatherService->fetchAndCache($wilayah);

            if (!$sukses && !$dataHariIni) {
                return response()->json(['message' => 'Gagal mengambil data cuaca dan tidak ada cache tersedia'], 502);
            }
        }

        $historis = DataCuaca::where('wilayah_kode', $kode)
            ->where('is_forecast', false)
            ->orderByDesc('tanggal')
            ->limit(1)
            ->get();

        $forecast = DataCuaca::where('wilayah_kode', $kode)
            ->where('is_forecast', true)
            ->orderBy('tanggal')
            ->get();

        return response()->json([
            'wilayah' => $wilayah->only(['kode', 'nama', 'tingkat', 'latitude', 'longitude']),
            'hari_ini' => $historis->first(),
            'forecast_14_hari' => $forecast,
        ]);
    }
}