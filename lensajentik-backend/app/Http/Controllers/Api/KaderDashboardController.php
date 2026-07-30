<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wilayah;
use App\Models\SkorRisiko;
use App\Models\AbjLaporan;
use Carbon\Carbon;

class KaderDashboardController extends Controller
{
    /**
     * GET /api/kader/dashboard
     * Merangkum data untuk dashboard kader berdasarkan wilayah_kode yang ditugaskan.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Pastikan user adalah kader dan memiliki wilayah binaan
        if ($user->role !== 'kader' || !$user->wilayah_kode) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses atau belum ditugaskan ke wilayah manapun.'
            ], 403);
        }

        $wilayahKode = $user->wilayah_kode;
        $wilayah = Wilayah::find($wilayahKode);

        // 1. Skor Risiko Terkini
        $skorRisiko = SkorRisiko::where('wilayah_kode', $wilayahKode)
            ->where('jenis_penyakit', 'dbd')
            ->where('is_prediksi', false)
            ->orderByDesc('tanggal')
            ->first();

        // 2. Ringkasan Laporan ABJ (30 Hari Terakhir)
        $batasWaktu = Carbon::now()->subDays(30);
        $abjBulanIni = AbjLaporan::where('wilayah_kode', $wilayahKode)
            ->where('tanggal_pemeriksaan', '>=', $batasWaktu)
            ->get();

        $rataRataAbj = $abjBulanIni->avg('abj_persen');
        $totalRumahDiperiksa = $abjBulanIni->sum('jumlah_rumah_diperiksa');
        $totalRumahPositif = $abjBulanIni->sum('jumlah_rumah_positif_jentik');

        // 3. Tugas Pending (Cek apakah sudah lapor ABJ minggu ini)
        $batasMingguIni = Carbon::now()->subDays(7);
        $sudahLaporMingguIni = AbjLaporan::where('kader_id', $user->id)
            ->where('tanggal_pemeriksaan', '>=', $batasMingguIni)
            ->exists();

        $tugasPending = [];
        if (!$sudahLaporMingguIni) {
            $tugasPending[] = [
                'id' => 'tugas-abj',
                'judul' => 'Input Pemeriksaan Jentik Rutin',
                'deskripsi' => 'Anda belum memasukkan data hasil pemeriksaan jentik berkala untuk minggu ini.',
                'tipe' => 'reminder_pemeriksaan'
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'kader' => [
                    'nama' => $user->name,
                    'wilayah_binaan' => $wilayah ? $wilayah->nama : 'Tidak diketahui',
                ],
                'skor_risiko_terkini' => $skorRisiko ? [
                    'skor' => $skorRisiko->skor,
                    'level' => $skorRisiko->level_risiko,
                    'tanggal' => $skorRisiko->tanggal,
                ] : null,
                'ringkasan_abj' => [
                    'rata_rata_persen' => round($rataRataAbj ?? 0, 2),
                    'total_rumah_diperiksa' => $totalRumahDiperiksa,
                    'total_rumah_positif' => $totalRumahPositif,
                    'jumlah_laporan' => $abjBulanIni->count(),
                ],
                'tugas_pending' => $tugasPending,
            ]
        ]);
    }
}
