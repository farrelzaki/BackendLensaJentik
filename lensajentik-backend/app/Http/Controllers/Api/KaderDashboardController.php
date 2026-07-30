<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wilayah;
use App\Models\SkorRisiko;
use App\Models\PrediksiRisiko;
use App\Models\AbjLaporan;
use App\Models\LaporanWarga;
use Carbon\Carbon;

class KaderDashboardController extends Controller
{
    /**
     * GET /api/kader/dashboard
     *
     * Dashboard lengkap kader kesehatan — merangkum data wilayah binaan:
     *   - Skor risiko DBD & Malaria terkini
     *   - Ringkasan ABJ (30 hari terakhir)
     *   - Statistik laporan warga aktif
     *   - Prediksi 7 hari ke depan
     *   - Tugas pending (reminder input ABJ)
     *   - Riwayat ABJ terbaru untuk chart tren
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Validasi akses: harus kader dan punya wilayah binaan
        if ($user->role !== 'kader' || !$user->wilayah_kode) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses atau belum ditugaskan ke wilayah manapun.',
            ], 403);
        }

        $wilayahKode = $user->wilayah_kode;
        $wilayah = Wilayah::find($wilayahKode);

        // ═══════════════════════════════════════════════════════════════════
        // 1. Skor Risiko Terkini (DBD & Malaria)
        // ═══════════════════════════════════════════════════════════════════
        $skorDbd = SkorRisiko::where('wilayah_kode', $wilayahKode)
            ->where('jenis_penyakit', 'dbd')
            ->where('is_prediksi', false)
            ->orderByDesc('tanggal')
            ->first();

        $skorMalaria = SkorRisiko::where('wilayah_kode', $wilayahKode)
            ->where('jenis_penyakit', 'malaria')
            ->where('is_prediksi', false)
            ->orderByDesc('tanggal')
            ->first();

        // ═══════════════════════════════════════════════════════════════════
        // 2. Prediksi 7 Hari ke Depan
        // ═══════════════════════════════════════════════════════════════════
        $prediksiDbd = PrediksiRisiko::where('wilayah_kode', $wilayahKode)
            ->where('jenis_penyakit', 'dbd')
            ->where('tanggal_prediksi', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal_prediksi')
            ->limit(7)
            ->get();

        // ═══════════════════════════════════════════════════════════════════
        // 3. Ringkasan ABJ (30 Hari Terakhir)
        // ═══════════════════════════════════════════════════════════════════
        $batas30Hari = Carbon::now()->subDays(30);
        $abjBulanIni = AbjLaporan::where('wilayah_kode', $wilayahKode)
            ->where('tanggal_pemeriksaan', '>=', $batas30Hari)
            ->get();

        $rataRataAbj = $abjBulanIni->avg('abj_persen');
        $totalRumahDiperiksa = $abjBulanIni->sum('jumlah_rumah_diperiksa');
        $totalRumahPositif = $abjBulanIni->sum('jumlah_rumah_positif');
        $jumlahLaporanAbj = $abjBulanIni->count();

        // ═══════════════════════════════════════════════════════════════════
        // 4. Statistik Laporan Warga (Wilayah Binaan, 30 Hari Terakhir)
        // ═══════════════════════════════════════════════════════════════════
        $laporanAktif = LaporanWarga::where('wilayah_kode', $wilayahKode)
            ->where('created_at', '>=', $batas30Hari)
            ->where('status', '!=', 'selesai')
            ->count();

        $laporanTotal = LaporanWarga::where('wilayah_kode', $wilayahKode)
            ->where('created_at', '>=', $batas30Hari)
            ->count();

        $laporanBelumDitangani = LaporanWarga::where('wilayah_kode', $wilayahKode)
            ->where('status', 'belum_ditangani')
            ->count();

        // ═══════════════════════════════════════════════════════════════════
        // 5. Tugas Pending — Cek apakah kader sudah input ABJ minggu ini
        // ═══════════════════════════════════════════════════════════════════
        $batasMingguIni = Carbon::now()->subDays(7);
        $sudahLaporMingguIni = AbjLaporan::where('user_id', $user->id)
            ->where('tanggal_pemeriksaan', '>=', $batasMingguIni)
            ->exists();

        $tugasPending = [];
        if (!$sudahLaporMingguIni) {
            $tugasPending[] = [
                'id'           => 'tugas-abj',
                'judul'        => 'Input Pemeriksaan Jentik Rutin',
                'deskripsi'    => 'Anda belum memasukkan data hasil pemeriksaan jentik berkala untuk minggu ini.',
                'tipe'         => 'reminder_pemeriksaan',
                'deadline'     => Carbon::now()->endOfWeek()->toDateString(),
                'aksi_url'     => '/kader/abj',
                'aksi_label'   => 'Input Sekarang',
            ];
        }

        // Jika ada laporan warga yang belum ditangani, tambahkan ke tugas
        if ($laporanBelumDitangani > 0) {
            $tugasPending[] = [
                'id'           => 'tugas-verifikasi-laporan',
                'judul'        => "{$laporanBelumDitangani} Laporan Warga Perlu Ditindaklanjuti",
                'deskripsi'    => 'Ada laporan genangan jentik dari warga di wilayah binaan Anda yang perlu diverifikasi dan ditindaklanjuti.',
                'tipe'         => 'tindak_lanjut_laporan',
                'laporan_count'=> $laporanBelumDitangani,
                'aksi_url'     => '/kader/laporan',
                'aksi_label'   => 'Lihat Laporan',
            ];
        }

        // ═══════════════════════════════════════════════════════════════════
        // 6. Konfidensi Level Wilayah
        // ═══════════════════════════════════════════════════════════════════
        $adaAbj14Hari = AbjLaporan::where('wilayah_kode', $wilayahKode)
            ->where('tanggal_pemeriksaan', '>=', Carbon::now()->subDays(14))
            ->exists();
        $confidenceLevel = $adaAbj14Hari ? 'kuat' : 'lemah';

        // ═══════════════════════════════════════════════════════════════════
        // Response
        // ═══════════════════════════════════════════════════════════════════
        return response()->json([
            'success' => true,
            'data'    => [
                // Info kader
                'kader' => [
                    'nama'           => $user->nama,
                    'email'          => $user->email,
                    'phone'          => $user->phone,
                    'role'           => $user->role,
                    'wilayah_kode'   => $wilayahKode,
                    'wilayah_binaan' => $wilayah ? $wilayah->nama : 'Tidak diketahui',
                ],

                // Skor risiko terkini (DBD & Malaria)
                'skor_risiko_terkini' => [
                    'dbd' => $skorDbd ? [
                        'skor'            => $skorDbd->skor,
                        'level'           => $skorDbd->level_risiko,
                        'confidence'      => $skorDbd->confidence_level,
                        'tanggal'         => $skorDbd->tanggal,
                        'faktor'          => $skorDbd->faktor_perhitungan,
                    ] : null,
                    'malaria' => $skorMalaria ? [
                        'skor'            => $skorMalaria->skor,
                        'level'           => $skorMalaria->level_risiko,
                        'confidence'      => $skorMalaria->confidence_level,
                        'tanggal'         => $skorMalaria->tanggal,
                        'faktor'          => $skorMalaria->faktor_perhitungan,
                    ] : null,
                ],

                // Ringkasan ABJ
                'ringkasan_abj' => [
                    'rata_rata_persen'       => round($rataRataAbj ?? 0, 2),
                    'total_rumah_diperiksa'  => $totalRumahDiperiksa,
                    'total_rumah_positif'    => $totalRumahPositif,
                    'jumlah_laporan'         => $jumlahLaporanAbj,
                    'persentase_bebas_jentik'=> $totalRumahDiperiksa > 0
                        ? round((($totalRumahDiperiksa - $totalRumahPositif) / $totalRumahDiperiksa) * 100, 2)
                        : 0,
                ],

                // Laporan warga
                'laporan_warga' => [
                    'total_30_hari'          => $laporanTotal,
                    'aktif'                  => $laporanAktif,
                    'belum_ditangani'        => $laporanBelumDitangani,
                ],

                // Prediksi 7 hari
                'prediksi' => $prediksiDbd->map(fn($p) => [
                    'tanggal'     => $p->tanggal_prediksi,
                    'skor'        => $p->skor,
                    'level_risiko'=> $p->level_risiko,
                    'confidence'  => $p->confidence_level,
                ])->values(),

                // Info wilayah
                'wilayah' => $wilayah ? [
                    'kode'        => $wilayah->kode,
                    'nama'        => $wilayah->nama,
                    'tingkat'     => $wilayah->tingkat,
                    'elevasi'     => $wilayah->elevasi,
                    'confidence'  => $confidenceLevel,
                ] : null,

                // Tugas pending
                'tugas_pending' => $tugasPending,
            ],
        ]);
    }
}
