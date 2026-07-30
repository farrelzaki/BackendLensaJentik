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
     *
     * Tanpa kode → agregasi nasional (per kabupaten).
     * Dengan kode kecamatan → data spesifik kecamatan itu.
     * Dengan kode kabupaten → data kumulatif seluruh kecamatan dalam kabupaten
     *                          + breakdown per kecamatan.
     */
    public function ringkasan(Request $request)
    {
        $request->validate(['wilayah_kode' => 'nullable|exists:wilayah,kode']);
        $kode = $request->wilayah_kode;
        $wilayah = $kode ? Wilayah::find($kode) : null;

        // ── Tanpa kode: nasional (per kabupaten) ──────────────────
        if (!$wilayah) {
            return $this->ringkasanNasional();
        }

        // ── Kecamatan: data langsung ─────────────────────────────
        if ($wilayah->tingkat === 'kecamatan') {
            return $this->ringkasanKecamatan($wilayah);
        }

        // ── Kabupaten: agregasi dari kecamatan anak ──────────────
        if ($wilayah->tingkat === 'kabupaten') {
            return $this->ringkasanKabupaten($wilayah);
        }

        // ── Provinsi: kembalikan pesan (terlalu berat) ───────────
        return response()->json(['message' => 'Gunakan tingkat kabupaten atau kecamatan.'], 400);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * KECAMATAN — data spesifik 1 kecamatan
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function ringkasanKecamatan(Wilayah $wilayah)
    {
        $batas30Hari = now()->subDays(30);
        $kode = $wilayah->kode;

        $skorTerkini = SkorRisiko::where('wilayah_kode', $kode)
            ->where('jenis_penyakit', 'dbd')->where('is_prediksi', false)
            ->orderByDesc('tanggal')->first();

        $trenAbj = AbjLaporan::where('wilayah_kode', $kode)
            ->where('tanggal_pemeriksaan', '>=', $batas30Hari)
            ->orderBy('tanggal_pemeriksaan')
            ->get(['tanggal_pemeriksaan', 'abj_persen']);

        $rataAbj = $trenAbj->isNotEmpty() ? round($trenAbj->avg('abj_persen'), 2) : 0;

        $laporanPerStatus = LaporanWarga::where('wilayah_kode', $kode)
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')->pluck('jumlah', 'status');

        return response()->json([
            'wilayah'           => $wilayah->only(['kode', 'nama', 'tingkat']),
            'ringkasan'         => [
                'rata_abj'            => $rataAbj,
                'skor_risiko'         => $skorTerkini?->skor,
                'level_risiko'         => $skorTerkini?->level_risiko,
                'total_laporan'       => $laporanPerStatus->sum(),
                'zona_hijau'          => $rataAbj >= 95 ? 1 : 0,
                'zona_merah'          => $rataAbj < 90 ? 1 : 0,
                'total_wilayah'       => 1,
                'wilayah_dengan_data' => $trenAbj->isNotEmpty() ? 1 : 0,
            ],
            'tren_abj'          => $trenAbj,
            'laporan_per_status'=> $laporanPerStatus,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * KABUPATEN — kumulatif dari seluruh kecamatan anak
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function ringkasanKabupaten(Wilayah $wilayah)
    {
        $batas30Hari = now()->subDays(30);

        // Semua kecamatan dalam kabupaten ini
        $kecamatan = Wilayah::where('parent_kode', $wilayah->kode)
            ->where('tingkat', 'kecamatan')
            ->get();

        $kecKodes = $kecamatan->pluck('kode');

        if ($kecKodes->isEmpty()) {
            return response()->json([
                'wilayah'   => $wilayah->only(['kode', 'nama', 'tingkat']),
                'ringkasan' => $this->ringkasanKosong(count($kecamatan)),
                'per_wilayah' => [],
                'tren_abj'  => [],
                'laporan_per_status' => [],
            ]);
        }

        // ── Agregasi skor risiko per kecamatan ─────────────────
        $skorPerKec = SkorRisiko::whereIn('wilayah_kode', $kecKodes)
            ->where('jenis_penyakit', 'dbd')->where('is_prediksi', false)
            ->whereDate('tanggal', now()->toDateString())
            ->get(['wilayah_kode', 'skor', 'level_risiko']);

        $skorRata = $skorPerKec->isNotEmpty() ? round($skorPerKec->avg('skor'), 1) : null;

        // ── Agregasi ABJ per kecamatan ─────────────────────────
        $abjPerKec = AbjLaporan::whereIn('wilayah_kode', $kecKodes)
            ->where('tanggal_pemeriksaan', '>=', $batas30Hari)
            ->get(['wilayah_kode', 'abj_persen']);

        $rataAbj = $abjPerKec->isNotEmpty() ? round($abjPerKec->avg('abj_persen'), 2) : 0;

        // ── Agregasi laporan ───────────────────────────────────
        $laporanPerStatus = LaporanWarga::whereIn('wilayah_kode', $kecKodes)
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')->pluck('jumlah', 'status');

        // ── Tren ABJ (rata-rata per minggu) ───────────────────
        $trenAbj = AbjLaporan::whereIn('wilayah_kode', $kecKodes)
            ->where('tanggal_pemeriksaan', '>=', $batas30Hari)
            ->selectRaw("DATE_TRUNC('week', tanggal_pemeriksaan) as minggu, ROUND(AVG(abj_persen), 2) as abj_persen")
            ->groupBy('minggu')->orderBy('minggu')
            ->get()
            ->map(fn($r) => ['tanggal_pemeriksaan' => $r->minggu, 'abj_persen' => $r->abj_persen]);

        // ── Breakdown per kecamatan ─────────────────────────────
        // Gabungkan skor + ABJ per kecamatan
        $skorByKec = $skorPerKec->keyBy('wilayah_kode');
        $abjByKec = $abjPerKec->groupBy('wilayah_kode')
            ->map(fn($items) => round($items->avg('abj_persen'), 2));

        $perWilayah = $kecamatan->map(function ($kec) use ($skorByKec, $abjByKec) {
            $skor = $skorByKec->get($kec->kode);
            return [
                'kode'  => $kec->kode,
                'nama'  => $kec->nama,
                'abj'   => $abjByKec->get($kec->kode),
                'skor'  => $skor?->skor,
                'level' => $skor?->level_risiko ?? 'belum_ada_data',
            ];
        })->values();

        // ── Zona ───────────────────────────────────────────────
        $zonaHijau = $skorPerKec->where('level_risiko', 'rendah')->count();
        $zonaMerah = $skorPerKec->where('level_risiko', 'tinggi')->count();
        $levelKumulatif = $skorRata !== null
            ? ($skorRata >= 70 ? 'tinggi' : ($skorRata >= 40 ? 'sedang' : 'rendah'))
            : null;

        return response()->json([
            'wilayah'   => $wilayah->only(['kode', 'nama', 'tingkat']),
            'ringkasan' => [
                'rata_abj'            => $rataAbj,
                'skor_risiko'         => $skorRata,
                'level_risiko'         => $levelKumulatif,
                'total_laporan'       => $laporanPerStatus->sum(),
                'zona_hijau'          => $zonaHijau,
                'zona_merah'          => $zonaMerah,
                'total_wilayah'       => $kecamatan->count(),
                'wilayah_dengan_data' => $skorPerKec->unique('wilayah_kode')->count(),
            ],
            'per_wilayah'        => $perWilayah,
            'tren_abj'           => $trenAbj,
            'laporan_per_status' => $laporanPerStatus,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * NASIONAL — agregasi per kabupaten
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function ringkasanNasional()
    {
        $batas30Hari = now()->subDays(30);

        // Semua kabupaten/kota di Indonesia
        $kabupaten = Wilayah::where('tingkat', 'kabupaten')->get();
        $kabKodes = $kabupaten->pluck('kode');

        // ── Agregasi: skor per kabupaten via kecamatan ──────────
        // Untuk setiap kabupaten, cari skor rata-rata kecamatan
        $skorPerKab = \DB::table('wilayah as kab')
            ->leftJoin('wilayah as kec', 'kec.parent_kode', '=', 'kab.kode')
            ->leftJoin('skor_risiko as sr', function ($join) {
                $join->on('sr.wilayah_kode', '=', 'kec.kode')
                    ->where('sr.jenis_penyakit', '=', 'dbd')
                    ->where('sr.is_prediksi', '=', false)
                    ->whereDate('sr.tanggal', '=', now()->toDateString());
            })
            ->where('kab.tingkat', '=', 'kabupaten')
            ->where('kec.tingkat', '=', 'kecamatan')
            ->groupBy('kab.kode', 'kab.nama')
            ->select(
                'kab.kode',
                'kab.nama',
                \DB::raw('ROUND(AVG(sr.skor)::numeric, 1) as skor'),
                \DB::raw('COUNT(DISTINCT sr.wilayah_kode) as kec_data')
            )
            ->orderBy('skor', 'desc')
            ->get();

        // ── Hitung ringkasan ───────────────────────────────────
        $skorRata = $skorPerKab->where('skor', '>', 0)->isNotEmpty()
            ? round($skorPerKab->where('skor', '>', 0)->avg('skor'), 1)
            : null;

        $zonaMerah = $skorPerKab->filter(fn($k) => $k->skor >= 70)->count();
        $zonaHijau = $skorPerKab->filter(fn($k) => $k->skor > 0 && $k->skor < 40)->count();
        $wilDenganData = $skorPerKab->where('kec_data', '>', 0)->count();

        $levelNasional = $skorRata !== null
            ? ($skorRata >= 70 ? 'tinggi' : ($skorRata >= 40 ? 'sedang' : 'rendah'))
            : null;

        // ── ABJ nasional ───────────────────────────────────────
        $rataAbj = AbjLaporan::where('tanggal_pemeriksaan', '>=', $batas30Hari)
            ->avg('abj_persen');
        $rataAbj = $rataAbj !== null ? round($rataAbj, 2) : 0;

        // ── Laporan nasional ───────────────────────────────────
        $laporanPerStatus = LaporanWarga::selectRaw('status, count(*) as jumlah')
            ->groupBy('status')->pluck('jumlah', 'status');

        // ── Per kabupaten (top 20) ─────────────────────────────
        $perWilayah = $skorPerKab->take(20)->map(fn($k) => [
            'kode'  => $k->kode,
            'nama'  => $k->nama,
            'skor'  => $k->skor > 0 ? (float) $k->skor : null,
            'level' => $k->skor > 0
                ? ($k->skor >= 70 ? 'tinggi' : ($k->skor >= 40 ? 'sedang' : 'rendah'))
                : 'belum_ada_data',
            'kecamatan_dengan_data' => (int) $k->kec_data,
        ])->values();

        return response()->json([
            'wilayah'   => ['kode' => null, 'nama' => 'Indonesia', 'tingkat' => 'nasional'],
            'ringkasan' => [
                'rata_abj'            => $rataAbj,
                'skor_risiko'         => $skorRata,
                'level_risiko'         => $levelNasional,
                'total_laporan'       => $laporanPerStatus->sum(),
                'zona_hijau'          => $zonaHijau,
                'zona_merah'          => $zonaMerah,
                'total_wilayah'       => $kabupaten->count(),
                'wilayah_dengan_data' => $wilDenganData,
            ],
            'per_wilayah'        => $perWilayah,
            'tren_abj'           => [],
            'laporan_per_status' => $laporanPerStatus,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * Helper
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function ringkasanKosong(int $totalWilayah): array
    {
        return [
            'rata_abj'            => 0,
            'skor_risiko'         => null,
            'level_risiko'         => null,
            'total_laporan'       => 0,
            'zona_hijau'          => 0,
            'zona_merah'          => 0,
            'total_wilayah'       => $totalWilayah,
            'wilayah_dengan_data' => 0,
        ];
    }

    /**
     * GET /api/statistik/bandingkan
     *
     * Tanpa param → top 10 kabupaten risiko tertinggi.
     * Dengan parent_kode → semua kecamatan dalam kabupaten itu.
     */
    public function bandingkan(Request $request)
    {
        if ($request->filled('parent_kode')) {
            $anak = Wilayah::where('parent_kode', $request->parent_kode)
                ->where('tingkat', 'kecamatan')->pluck('kode');
            $hasil = SkorRisiko::whereIn('wilayah_kode', $anak)
                ->where('jenis_penyakit', 'dbd')->where('is_prediksi', false)
                ->whereDate('tanggal', now()->toDateString())
                ->with('wilayah:kode,nama')
                ->orderBy('skor', 'desc')->get();
            return response()->json(['data' => $hasil]);
        }

        // Default: top 10 kabupaten
        $hasil = SkorRisiko::where('jenis_penyakit', 'dbd')
            ->where('is_prediksi', false)
            ->whereDate('tanggal', now()->toDateString())
            ->with('wilayah:kode,nama')
            ->orderBy('skor', 'desc')->limit(10)->get();

        return response()->json(['data' => $hasil]);
    }
}
