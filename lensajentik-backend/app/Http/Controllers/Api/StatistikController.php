<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use App\Models\SkorRisiko;
use App\Models\AbjLaporan;
use App\Models\LaporanWarga;
use Illuminate\Http\Request;

class StatistikController extends Controller
{
    /**
     * GET /api/statistik/ringkasan?wilayah_kode=xxx
     * Publik, tanpa login. Sesuai revisi: statistik digabung jadi 1 halaman publik.
     */
    public function ringkasan(Request $request)
    {
        $request->validate(['wilayah_kode' => 'required|exists:wilayah,kode']);

        $kode = $request->wilayah_kode;
        $batas30Hari = now()->subDays(30);

        $trenSkorRisiko = SkorRisiko::where('wilayah_kode', $kode)
            ->where('jenis_penyakit', 'dbd')
            ->where('is_prediksi', false)
            ->orderBy('tanggal')
            ->limit(30)
            ->get(['tanggal', 'skor', 'level_risiko']);

        $trenAbj = AbjLaporan::where('wilayah_kode', $kode)
            ->where('tanggal_pemeriksaan', '>=', $batas30Hari)
            ->orderBy('tanggal_pemeriksaan')
            ->get(['tanggal_pemeriksaan', 'abj_persen']);

        $laporanPerStatus = LaporanWarga::where('wilayah_kode', $kode)
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        return response()->json([
            'wilayah' => Wilayah::find($kode)->only(['kode', 'nama', 'tingkat']),
            'tren_skor_risiko' => $trenSkorRisiko,
            'tren_abj' => $trenAbj,
            'laporan_per_status' => $laporanPerStatus,
            'rata_rata_abj_30hari' => round($trenAbj->avg('abj_persen') ?? 0, 2),
        ]);
    }

    /**
     * GET /api/statistik/bandingkan?wilayah_kode[]=xxx&wilayah_kode[]=yyy
     * Publik, tanpa login.
     */
    public function bandingkan(Request $request)
    {
        $request->validate([
            'wilayah_kode' => 'required|array|min:2|max:10',
            'wilayah_kode.*' => 'exists:wilayah,kode',
        ]);

        $hasil = SkorRisiko::whereIn('wilayah_kode', $request->wilayah_kode)
            ->where('jenis_penyakit', 'dbd')
            ->where('is_prediksi', false)
            ->whereDate('tanggal', now()->toDateString())
            ->with('wilayah:kode,nama')
            ->get();

        return response()->json(['data' => $hasil]);
    }
}