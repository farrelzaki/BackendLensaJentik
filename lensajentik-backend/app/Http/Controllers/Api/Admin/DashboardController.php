<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use App\Models\SkorRisiko;
use App\Models\AbjLaporan;
use App\Models\LaporanWarga;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * GET /api/admin/dashboard/ringkasan?wilayah_kode=xxx
     * Ringkasan statistik 1 wilayah: tren skor risiko, ABJ, laporan warga.
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
     * GET /api/admin/dashboard/bandingkan?wilayah_kode[]=xxx&wilayah_kode[]=yyy
     * Bandingkan skor risiko terkini antar beberapa wilayah sekaligus.
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

    /**
     * GET /api/admin/dashboard/export-mentah?wilayah_kode=xxx
     * Data mentah untuk riset/analisis lanjutan (JSON, bukan file — export ke PDF/Excel dikerjakan terpisah).
     */
    public function exportMentah(Request $request)
    {
        $request->validate(['wilayah_kode' => 'required|exists:wilayah,kode']);

        return response()->json([
            'abj' => AbjLaporan::where('wilayah_kode', $request->wilayah_kode)->get(),
            'laporan_warga' => LaporanWarga::where('wilayah_kode', $request->wilayah_kode)->get(),
            'skor_risiko' => SkorRisiko::where('wilayah_kode', $request->wilayah_kode)->get(),
        ]);
    }
}