<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use App\Models\SkorRisiko;
use App\Services\RiskScoreService;
use App\Services\WeatherService;
use Illuminate\Http\Request;

class SkorRisikoController extends Controller
{
    public function __construct(
        protected RiskScoreService $riskScoreService,
        protected WeatherService $weatherService
    ) {}

    /**
     * GET /api/skor-risiko/{kode}?jenis=dbd
     * Hitung ulang & kembalikan skor risiko (hari ini + prediksi 14 hari) untuk 1 wilayah.
     */
    public function show(Request $request, string $kode)
    {
        $jenis = $request->query('jenis', 'dbd');

        $wilayah = Wilayah::find($kode);

        if (!$wilayah) {
            return response()->json(['message' => 'Wilayah tidak ditemukan'], 404);
        }

        // Pastikan data cuaca ada/fresh dulu sebelum hitung skor
        $this->weatherService->fetchAndCache($wilayah);

        $hasil = $this->riskScoreService->hitungDanSimpan($wilayah, $jenis);

        return response()->json([
            'wilayah' => $wilayah->only(['kode', 'nama', 'tingkat']),
            'jenis_penyakit' => $jenis,
            'skor_hari_ini' => collect($hasil)->firstWhere('is_prediksi', false),
            'prediksi' => collect($hasil)->where('is_prediksi', true)->values(),
        ]);
    }

    /**
     * GET /api/skor-risiko/peta?tingkat=kecamatan&parent_kode=3201&jenis=dbd
     * Untuk render peta: skor risiko hari ini semua wilayah dalam 1 area (misal semua kecamatan di 1 kabupaten).
     * Catatan: ini ambil dari data yang SUDAH dihitung sebelumnya (gak realtime), karena hitung semua sekaligus terlalu berat untuk 1 request.
     */
    public function peta(Request $request)
    {
        $request->validate([
            'tingkat' => 'required|in:kabupaten,kecamatan,desa',
            'parent_kode' => 'required|string',
        ]);

        $jenis = $request->query('jenis', 'dbd');

        $wilayahList = Wilayah::where('tingkat', $request->tingkat)
            ->where('parent_kode', $request->parent_kode)
            ->pluck('kode');

        $skor = SkorRisiko::whereIn('wilayah_kode', $wilayahList)
            ->where('jenis_penyakit', $jenis)
            ->where('is_prediksi', false)
            ->whereDate('tanggal', now()->timezone('Asia/Jakarta')->toDateString())
            ->with('wilayah:kode,nama,latitude,longitude')
            ->get();

        return response()->json(['data' => $skor]);
    }
}