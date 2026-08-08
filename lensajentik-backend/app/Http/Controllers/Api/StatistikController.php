<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use App\Models\SkorRisiko;
use App\Models\AbjLaporan;
use App\Models\LaporanWarga;
use App\Models\DataCuaca;
use App\Models\User;
use App\Exports\GapAbjExport;
use App\Exports\RisetExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

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
        $request->validate([
            'wilayah_kode'   => 'nullable|exists:wilayah,kode',
            'jenis_penyakit' => 'nullable|in:dbd,malaria',
            'dari'           => 'nullable|date',
            'sampai'         => 'nullable|date',
        ]);

        $kode = $request->wilayah_kode;
        $wilayah = $kode ? Wilayah::find($kode) : null;

        $jenis = $request->filled('jenis_penyakit') ? $request->jenis_penyakit : 'dbd';
        $dari = $request->filled('dari') ? $request->dari : null;
        $sampai = $request->filled('sampai') ? $request->sampai : null;

        // ── Tanpa kode: nasional (per kabupaten) ──────────────────
        if (!$wilayah) {
            return $this->ringkasanNasional($jenis, $dari, $sampai);
        }

        // ── Kecamatan: data langsung ─────────────────────────────
        if ($wilayah->tingkat === 'kecamatan') {
            return $this->ringkasanKecamatan($wilayah, $jenis, $dari, $sampai);
        }

        // ── Kabupaten: agregasi dari kecamatan anak ──────────────
        if ($wilayah->tingkat === 'kabupaten') {
            return $this->ringkasanKabupaten($wilayah, $jenis, $dari, $sampai);
        }

        // ── Provinsi: kembalikan pesan (terlalu berat) ───────────
        return response()->json(['message' => 'Gunakan tingkat kabupaten atau kecamatan.'], 400);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * KECAMATAN — data spesifik 1 kecamatan
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function ringkasanKecamatan(Wilayah $wilayah, string $jenis, ?string $dari = null, ?string $sampai = null)
    {
        $batas30Hari = now()->subDays(30);
        $kode = $wilayah->kode;

        $skorQuery = SkorRisiko::where('wilayah_kode', $kode)
            ->where('jenis_penyakit', $jenis)->where('is_prediksi', false);

        // Tanpa rentang tanggal → skor terbaru (perilaku lama).
        // Dengan rentang → skor terbaru di dalam rentang tsb.
        $this->applyTanggalRange($skorQuery, $dari, $sampai, 'tanggal', false);

        $skorTerkini = $skorQuery->orderByDesc('tanggal')->first();

        $trenAbj = AbjLaporan::where('wilayah_kode', $kode)
            ->where('tanggal_pemeriksaan', '>=', $batas30Hari)
            ->orderBy('tanggal_pemeriksaan')
            ->get(['tanggal_pemeriksaan', 'abj_persen']);

        // ── Tren skor risiko harian (sesuai rentang tanggal & jenis) ──
        $trenSkorQuery = SkorRisiko::where('wilayah_kode', $kode)
            ->where('jenis_penyakit', $jenis)
            ->where('is_prediksi', false);
        $this->applyTanggalRange($trenSkorQuery, $dari, $sampai, 'tanggal', false);
        $trenSkor = $trenSkorQuery->orderBy('tanggal')
            ->get(['tanggal', 'skor', 'level_risiko']);

        $rataAbj = $trenAbj->isNotEmpty() ? round($trenAbj->avg('abj_persen'), 2) : 0;

        $laporanPerStatus = LaporanWarga::where('wilayah_kode', $kode)
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')->pluck('jumlah', 'status');

        // ── Confidence summary ──────────────────────────────────
        // Untuk 1 kecamatan cukup satu nilai: confidence skor terbaru.
        $confidence = [
            'kuat'  => ['jumlah' => 0, 'persen' => 0],
            'lemah' => ['jumlah' => 0, 'persen' => 0],
        ];
        if ($skorTerkini?->confidence_level === 'kuat') {
            $confidence = [
                'kuat'  => ['jumlah' => 1, 'persen' => 100],
                'lemah' => ['jumlah' => 0, 'persen' => 0],
            ];
        } elseif ($skorTerkini?->confidence_level === 'lemah') {
            $confidence = [
                'kuat'  => ['jumlah' => 0, 'persen' => 0],
                'lemah' => ['jumlah' => 1, 'persen' => 100],
            ];
        }

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
                'confidence_summary'  => $confidence,
            ],
            'tren_skor_risiko'  => $trenSkor,
            'tren_abj'          => $trenAbj,
            'laporan_per_status'=> $laporanPerStatus,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * KABUPATEN — kumulatif dari seluruh kecamatan anak
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function ringkasanKabupaten(Wilayah $wilayah, string $jenis, ?string $dari = null, ?string $sampai = null)
    {
        $batas30Hari = now()->subDays(30);

        // Semua kecamatan dalam kabupaten ini
        $kecamatan = Wilayah::where('parent_kode', $wilayah->kode)
            ->where('tingkat', 'kecamatan')
            ->get();

        $kecKodes = $kecamatan->pluck('kode');

        if ($kecKodes->isEmpty()) {
            return response()->json([
                'wilayah'           => $wilayah->only(['kode', 'nama', 'tingkat']),
                'ringkasan'         => $this->ringkasanKosong(count($kecamatan)),
                'per_wilayah'       => [],
                'tren_skor_risiko'  => [],
                'tren_abj'          => [],
                'laporan_per_status'=> [],
            ]);
        }

        // ── Agregasi skor risiko per kecamatan ─────────────────
        // Skor TERBARU per kecamatan dalam rentang tanggal (tidak ada kecamatan
        // yang terhitung ganda meskipun punya banyak baris di rentang tsb).
        $skorPerKec = $this->latestSkorPerWilayah($jenis, $dari, $sampai, $kecKodes->all())
            ->get(['wilayah_kode', 'skor', 'level_risiko', 'confidence_level']);

        $skorRata = $skorPerKec->isNotEmpty() ? round($skorPerKec->avg('skor'), 1) : null;

        // ── Confidence summary ──────────────────────────────────
        $confidence = $this->confidenceSummary($skorPerKec);

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

        // ── Tren skor risiko (rata-rata harian seluruh kecamatan) ──
        $trenSkorQuery = SkorRisiko::whereIn('wilayah_kode', $kecKodes)
            ->where('jenis_penyakit', $jenis)
            ->where('is_prediksi', false);
        $this->applyTanggalRange($trenSkorQuery, $dari, $sampai, 'tanggal', false);
        $trenSkor = $trenSkorQuery->selectRaw('tanggal, ROUND(AVG(skor)::numeric, 1) as skor')
            ->groupBy('tanggal')->orderBy('tanggal')
            ->get()
            ->map(function ($r) {
                $skor = $r->skor !== null ? (float) $r->skor : null;
                return [
                    'tanggal'      => $r->tanggal->toDateString(),
                    'skor'         => $skor,
                    'level_risiko' => $skor !== null
                        ? ($skor >= 70 ? 'tinggi' : ($skor >= 40 ? 'sedang' : 'rendah'))
                        : null,
                ];
            });

        // ── Breakdown per kecamatan ─────────────────────────────
        // Gabungkan skor + ABJ per kecamatan
        $skorByKec = $skorPerKec->keyBy('wilayah_kode');
        $abjByKec = $abjPerKec->groupBy('wilayah_kode')
            ->map(fn($items) => round($items->avg('abj_persen'), 2));

        $perWilayah = $kecamatan->map(function ($kec) use ($skorByKec, $abjByKec) {
            $skor = $skorByKec->get($kec->kode);
            return [
                'kode'             => $kec->kode,
                'nama'             => $kec->nama,
                'abj'              => $abjByKec->get($kec->kode),
                'skor'             => $skor?->skor,
                'level'            => $skor?->level_risiko ?? 'belum_ada_data',
                'confidence_level' => $skor?->confidence_level,
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
                'confidence_summary'  => $confidence,
            ],
            'per_wilayah'        => $perWilayah,
            'tren_skor_risiko'   => $trenSkor,
            'tren_abj'           => $trenAbj,
            'laporan_per_status' => $laporanPerStatus,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * NASIONAL — agregasi per kabupaten
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function ringkasanNasional(string $jenis, ?string $dari = null, ?string $sampai = null)
    {
        $batas30Hari = now()->subDays(30);

        // Semua kabupaten/kota di Indonesia
        $kabupaten = Wilayah::where('tingkat', 'kabupaten')->get();
        $kabKodes = $kabupaten->pluck('kode');

        // ── Agregasi: skor per kabupaten via kecamatan ──────────
        // Untuk setiap kabupaten, cari skor rata-rata kecamatan
        $skorPerKab = \DB::table('wilayah as kab')
            ->leftJoin('wilayah as kec', 'kec.parent_kode', '=', 'kab.kode')
            ->leftJoin('skor_risiko as sr', function ($join) use ($jenis, $dari, $sampai) {
                $join->on('sr.wilayah_kode', '=', 'kec.kode')
                    ->where('sr.jenis_penyakit', '=', $jenis)
                    ->where('sr.is_prediksi', '=', false);
                $this->applyTanggalRange($join, $dari, $sampai, 'sr.tanggal');
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

        // ── Confidence summary ──────────────────────────────────
        // Berapa kecamatan yang datanya KUAT (ada ABJ kader) vs LEMAH (murni cuaca),
        // berdasarkan skor TERBARU per kecamatan di dalam rentang tanggal.
        $confidence = $this->confidenceSummary(
            $this->latestSkorPerWilayah($jenis, $dari, $sampai)->get(['confidence_level'])
        );

        // ── Ranking kecamatan (top 10 hijau & top 10 merah) ────
        // Pakai skor TERBARU per wilayah di dalam rentang tanggal.
        $topHijau = $this->latestSkorPerWilayah($jenis, $dari, $sampai)
            ->where('level_risiko', 'rendah')
            ->whereNotNull('skor')
            ->with('wilayah:kode,nama')
            ->orderBy('skor')
            ->limit(10)
            ->get()
            ->map(fn($s) => [
                'kode'              => $s->wilayah_kode,
                'nama'              => $s->wilayah->nama ?? '?',
                'skor'              => (float) $s->skor,
                'level'             => $s->level_risiko,
                'confidence_level'  => $s->confidence_level,
            ]);

        $topMerah = $this->latestSkorPerWilayah($jenis, $dari, $sampai)
            ->where('level_risiko', 'tinggi')
            ->whereNotNull('skor')
            ->with('wilayah:kode,nama')
            ->orderBy('skor', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($s) => [
                'kode'              => $s->wilayah_kode,
                'nama'              => $s->wilayah->nama ?? '?',
                'skor'              => (float) $s->skor,
                'level'             => $s->level_risiko,
                'confidence_level'  => $s->confidence_level,
            ]);

        return response()->json([
            'wilayah'   => ['kode' => null, 'nama' => 'Indonesia', 'tingkat' => 'nasional'],
            'ringkasan' => [
                'rata_abj'            => $rataAbj,
                'skor_risiko'         => $skorRata,
                'level_risiko'         => $levelNasional,
                'total_laporan'       => $laporanPerStatus->sum(),
                'zona_hijau'          => $zonaHijau,
                'zona_merah'          => $zonaMerah,
                'zona_sedang'         => $skorPerKab->filter(fn($k) => $k->skor >= 40 && $k->skor < 70)->count(),
                'total_wilayah'       => $kabupaten->count(),
                'wilayah_dengan_data' => $wilDenganData,
                'confidence_summary'  => $confidence,
            ],
            'top_hijau'          => $topHijau,
            'top_merah'          => $topMerah,
            'tren_skor_risiko'   => [],
            'tren_abj'           => [],
            'laporan_per_status' => $laporanPerStatus,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * Helper
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Terapkan rentang tanggal ke sebuah query.
     *
     * - $dari & $sampai → WHERE $column BETWEEN $dari AND $sampai
     * - hanya $dari     → $column >= $dari
     * - hanya $sampai   → $column <= $sampai
     * - tanpa rentang   → WHERE DATE($column) = hari ini (perilaku lama),
     *                     kecuali $defaultToday = false (tidak ada filter tanggal).
     */
    protected function applyTanggalRange($query, ?string $dari, ?string $sampai, string $column = 'tanggal', bool $defaultToday = true)
    {
        if ($dari && $sampai) {
            $query->whereBetween($column, [$dari, $sampai]);
        } elseif ($dari) {
            $query->where($column, '>=', $dari);
        } elseif ($sampai) {
            $query->where($column, '<=', $sampai);
        } elseif ($defaultToday) {
            $query->whereDate($column, now()->toDateString());
        }

        return $query;
    }

    /**
     * Skor risiko TERBARU per wilayah dalam rentang tanggal
     * (pola PostgreSQL DISTINCT ON).
     *
     * Untuk tiap wilayah_kode, ambil 1 baris dengan tanggal terbaru yang
     * memenuhi jenis_penyakit + is_prediksi = false + rentang tanggal.
     * Dipakai oleh ranking top_hijau/top_merah dan agregasi kabupaten agar
     * sebuah kecamatan tidak terhitung ganda di dalam rentang.
     */
    protected function latestSkorPerWilayah(string $jenis, ?string $dari, ?string $sampai, ?array $wilayahKodes = null)
    {
        $query = SkorRisiko::query()
            ->where('jenis_penyakit', $jenis)
            ->where('is_prediksi', false);

        if ($wilayahKodes !== null) {
            $query->whereIn('wilayah_kode', $wilayahKodes);
        }

        $this->applyTanggalRange($query, $dari, $sampai);

        // DISTINCT ON (wilayah_kode) … ORDER BY wilayah_kode, tanggal DESC
        $ids = (clone $query)
            ->selectRaw('DISTINCT ON (wilayah_kode) id')
            ->orderBy('wilayah_kode')
            ->orderByDesc('tanggal')
            ->pluck('id');

        return SkorRisiko::whereIn('id', $ids);
    }

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
            'confidence_summary'  => [
                'kuat'  => ['jumlah' => 0, 'persen' => 0],
                'lemah' => ['jumlah' => 0, 'persen' => 0],
            ],
        ];
    }

    /**
     * Hitung ringkasan confidence dari kumpulan skor risiko.
     *
     * $skorRows adalah collection (Eloquent) berisi baris skor dengan atribut
     * confidence_level. Menghasilkan jumlah + persen untuk 'kuat' vs 'lemah'.
     */
    protected function confidenceSummary($skorRows): array
    {
        $kuat  = $skorRows->where('confidence_level', 'kuat')->count();
        $lemah = $skorRows->where('confidence_level', 'lemah')->count();
        $total = $kuat + $lemah;

        return [
            'kuat' => [
                'jumlah' => $kuat,
                'persen' => $total > 0 ? round($kuat / $total * 100, 1) : 0,
            ],
            'lemah' => [
                'jumlah' => $lemah,
                'persen' => $total > 0 ? round($lemah / $total * 100, 1) : 0,
            ],
        ];
    }

    /**
     * GET /api/statistik/bandingkan
     *
     * Dengan wilayah_kode[] → bandingkan wilayah-wilayah tertentu (maks 5).
     * Dengan parent_kode → semua kecamatan dalam kabupaten itu.
     * Tanpa param → top 10 kabupaten risiko tertinggi.
     */
    public function bandingkan(Request $request)
    {
        $request->validate([
            'parent_kode'    => 'nullable|exists:wilayah,kode',
            'wilayah_kode'   => 'nullable|array|min:1|max:5',
            'wilayah_kode.*' => 'exists:wilayah,kode',
            'jenis'          => 'nullable|in:dbd,malaria',
        ]);

        $jenis = $request->filled('jenis') ? $request->jenis : 'dbd';

        // Bandingkan wilayah-wilayah tertentu (maks 5).
        if ($request->filled('wilayah_kode')) {
            return $this->bandingkanWilayah($request->input('wilayah_kode'), $jenis);
        }

        if ($request->filled('parent_kode')) {
            $anak = Wilayah::where('parent_kode', $request->parent_kode)
                ->where('tingkat', 'kecamatan')->pluck('kode');
            $hasil = SkorRisiko::whereIn('wilayah_kode', $anak)
                ->where('jenis_penyakit', $jenis)->where('is_prediksi', false)
                ->whereDate('tanggal', now()->toDateString())
                ->with('wilayah:kode,nama')
                ->orderBy('skor', 'desc')->get();
            return response()->json(['data' => $hasil]);
        }

        // Default: top 10 kabupaten
        $hasil = SkorRisiko::where('jenis_penyakit', $jenis)
            ->where('is_prediksi', false)
            ->whereDate('tanggal', now()->toDateString())
            ->with('wilayah:kode,nama')
            ->orderBy('skor', 'desc')->limit(10)->get();

        return response()->json(['data' => $hasil]);
    }

    /**
     * Bandingkan beberapa wilayah sekaligus (maks 5).
     *
     * Untuk setiap wilayah_kode diambil:
     * - Skor risiko TERBARU (jenis sesuai param)
     * - Rata-rata ABJ 30 hari terakhir
     * - Total laporan warga
     */
    protected function bandingkanWilayah(array $kodes, string $jenis)
    {
        $batas30Hari = now()->subDays(30);

        $wilayah = Wilayah::whereIn('kode', $kodes)->get()->keyBy('kode');

        // ── Skor risiko terbaru per wilayah (pola DISTINCT ON) ──
        $skorQuery = SkorRisiko::whereIn('wilayah_kode', $kodes)
            ->where('jenis_penyakit', $jenis)
            ->where('is_prediksi', false);

        $ids = (clone $skorQuery)
            ->selectRaw('DISTINCT ON (wilayah_kode) id')
            ->orderBy('wilayah_kode')
            ->orderByDesc('tanggal')
            ->pluck('id');

        $skorTerbaru = SkorRisiko::whereIn('id', $ids)
            ->get(['wilayah_kode', 'skor', 'level_risiko', 'confidence_level'])
            ->keyBy('wilayah_kode');

        // ── Rata-rata ABJ 30 hari terakhir per wilayah ──────────
        $abjPerWilayah = AbjLaporan::whereIn('wilayah_kode', $kodes)
            ->where('tanggal_pemeriksaan', '>=', $batas30Hari)
            ->get(['wilayah_kode', 'abj_persen'])
            ->groupBy('wilayah_kode')
            ->map(fn($items) => round($items->avg('abj_persen'), 1));

        // ── Total laporan warga per wilayah ─────────────────────
        $laporanPerWilayah = LaporanWarga::whereIn('wilayah_kode', $kodes)
            ->selectRaw('wilayah_kode, count(*) as jumlah')
            ->groupBy('wilayah_kode')
            ->pluck('jumlah', 'wilayah_kode');

        $data = collect($kodes)->map(function ($kode) use ($wilayah, $skorTerbaru, $abjPerWilayah, $laporanPerWilayah) {
            $w = $wilayah->get($kode);
            $s = $skorTerbaru->get($kode);

            return [
                'kode'             => $kode,
                'nama'             => $w?->nama ?? '?',
                'skor'             => $s ? round((float) $s->skor, 1) : null,
                'level_risiko'     => $s?->level_risiko,
                'confidence_level' => $s?->confidence_level,
                'abj'              => $abjPerWilayah->get($kode),
                'total_laporan'    => (int) ($laporanPerWilayah[$kode] ?? 0),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/statistik/kelengkapan-data
     *
     * Kelengkapan data dalam 30 hari terakhir:
     * - ABJ   : berapa kecamatan dari total kecamatan yang punya input ABJ.
     * - Cuaca : kapan data cuaca historis (bukan forecast) terakhir diperbarui.
     * - Kader : berapa kader aktif dari total kader aktif yang melaporkan ABJ.
     */
    public function kelengkapanData()
    {
        $batas30Hari = now()->subDays(30);

        // ── ABJ: kecamatan dengan input dalam 30 hari terakhir ─────────
        $kecamatanDenganAbj = AbjLaporan::where('tanggal_pemeriksaan', '>=', $batas30Hari)
            ->distinct()
            ->count('wilayah_kode');

        $totalKecamatan = Wilayah::where('tingkat', 'kecamatan')->count();

        // ── Cuaca: update terakhir data historis (bukan forecast) ──────
        $cuacaTerakhir = DataCuaca::where('is_forecast', false)
            ->max('updated_at');

        // ── Kader aktif yang melaporkan ABJ dalam 30 hari terakhir ─────
        $kaderAktifMelapor = User::where('role', 'kader')
            ->where('is_active', true)
            ->whereHas('abjLaporan', function ($q) use ($batas30Hari) {
                $q->where('tanggal_pemeriksaan', '>=', $batas30Hari);
            })
            ->count();

        $totalKaderAktif = User::where('role', 'kader')
            ->where('is_active', true)
            ->count();

        return response()->json([
            'abj' => [
                'kecamatan_dengan_abj' => (int) $kecamatanDenganAbj,
                'total_kecamatan'      => (int) $totalKecamatan,
                'persen'               => $totalKecamatan > 0
                    ? round(($kecamatanDenganAbj / $totalKecamatan) * 100, 1)
                    : 0,
            ],
            'cuaca_terakhir' => $cuacaTerakhir,
            'kader_aktif'    => [
                'aktif' => (int) $kaderAktifMelapor,
                'total' => (int) $totalKaderAktif,
                'persen'=> $totalKaderAktif > 0
                    ? round(($kaderAktifMelapor / $totalKaderAktif) * 100, 1)
                    : 0,
            ],
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * KORELASI CUACA vs SKOR RISIKO (Feature 5)
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * GET /api/statistik/korelasi-cuaca
     *
     * Titik data untuk scatter plot: korelasi antara variabel cuaca
     * (curah hujan / suhu / kelembapan) terhadap skor risiko, per kecamatan
     * per tanggal. Hanya memakai data historis (is_forecast = false untuk
     * cuaca, is_prediksi = false untuk skor) dalam 90 hari terakhir.
     *
     * - variabel   : curah_hujan | suhu | kelembapan (wajib)
     * - scope_kode : kode wilayah (provinsi/kabupaten/kecamatan). Opsional —
     *                kalau kosong berarti nasional.
     * - jenis      : dbd | malaria (default dbd)
     */
    public function korelasiCuaca(Request $request)
    {
        $request->validate([
            'variabel'   => 'required|in:curah_hujan,suhu,kelembapan',
            'scope_kode' => 'nullable|exists:wilayah,kode',
            'jenis'      => 'nullable|in:dbd,malaria',
        ]);

        $variabel = $request->variabel;
        $jenis = $request->filled('jenis') ? $request->jenis : 'dbd';

        $kolomCuaca = [
            'curah_hujan' => 'curah_hujan',
            'suhu'        => 'suhu_avg',
            'kelembapan'  => 'kelembapan_avg',
        ][$variabel];

        // ── Batasi scope ke kode-kode kecamatan ──────────────────
        $kecamatanKodes = null;
        if ($request->filled('scope_kode')) {
            $wilayah = Wilayah::find($request->scope_kode);
            $kecamatanKodes = $wilayah ? $wilayah->getAllKecamatanCodes() : [];
            if (empty($kecamatanKodes)) {
                return response()->json(['data' => []]);
            }
        }

        $batasTanggal = now()->subDays(90)->toDateString();

        $query = SkorRisiko::query()
            ->join('data_cuaca', function ($join) {
                $join->on('data_cuaca.wilayah_kode', '=', 'skor_risiko.wilayah_kode')
                     ->on('data_cuaca.tanggal', '=', 'skor_risiko.tanggal');
            })
            ->where('data_cuaca.is_forecast', false)
            ->where('skor_risiko.is_prediksi', false)
            ->where('skor_risiko.jenis_penyakit', $jenis)
            ->where('skor_risiko.tanggal', '>=', $batasTanggal)
            ->whereNotNull("data_cuaca.{$kolomCuaca}")
            ->select(
                'skor_risiko.wilayah_kode',
                'skor_risiko.tanggal',
                'skor_risiko.skor',
                \DB::raw("data_cuaca.{$kolomCuaca} as nilai_x")
            );

        if ($kecamatanKodes !== null) {
            $query->whereIn('skor_risiko.wilayah_kode', $kecamatanKodes);
        }

        $rows = $query->orderByDesc('skor_risiko.tanggal')
            ->limit(500)
            ->get();

        // ── Nama wilayah untuk label tooltip ─────────────────────
        $namaByKode = Wilayah::whereIn('kode', $rows->pluck('wilayah_kode')->unique())
            ->pluck('nama', 'kode');

        $data = $rows->map(function ($r) use ($namaByKode) {
            return [
                'wilayah_kode' => $r->wilayah_kode,
                'nama'         => $namaByKode[$r->wilayah_kode] ?? '?',
                'tanggal'      => $r->tanggal->toDateString(),
                'nilai_x'      => round((float) $r->nilai_x, 2),
                'skor'         => round((float) $r->skor, 2),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * GAP ABJ vs TARGET NASIONAL 95%
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * GET /api/statistik/gap-abj?dari=&sampai=&parent_kode=
     *
     * Gap analisis ABJ terhadap target nasional 95%.
     * Mengembalikan daftar kecamatan dengan rata-rata ABJ DI BAWAH 95%,
     * diurutkan dari yang paling rendah (terburuk) ke atas.
     *
     * - dari/sampai  : rentang tanggal (nullable → semua data).
     * - parent_kode  : kode kabupaten → hanya kecamatan di bawah kabupaten itu.
     */
    public function gapAbj(Request $request)
    {
        $request->validate([
            'dari'        => 'nullable|date',
            'sampai'      => 'nullable|date',
            'parent_kode' => 'nullable|exists:wilayah,kode',
        ]);

        $rows = $this->buildGapAbjQuery(
            $request->filled('dari') ? $request->dari : null,
            $request->filled('sampai') ? $request->sampai : null,
            $request->filled('parent_kode') ? $request->parent_kode : null
        )->get();

        $data = $rows->map(function ($row) {
            $rata = (float) $row->rata_abj;
            return [
                'kode'        => $row->kode,
                'nama'        => $row->nama,
                'rata_abj'    => $rata,
                'gap'         => round(95 - $rata, 2),
                'parent_kode' => $row->parent_kode,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/statistik/gap-abj/export?dari=&sampai=&parent_kode=
     *
     * Unduh hasil gap ABJ sebagai file Excel.
     */
    public function gapAbjExport(Request $request)
    {
        $request->validate([
            'dari'        => 'nullable|date',
            'sampai'      => 'nullable|date',
            'parent_kode' => 'nullable|exists:wilayah,kode',
        ]);

        $namaFile = 'gap-abj-' . now()->format('Ymd') . '.xlsx';

        return Excel::download(
            new GapAbjExport(
                $request->filled('dari') ? $request->dari : null,
                $request->filled('sampai') ? $request->sampai : null,
                $request->filled('parent_kode') ? $request->parent_kode : null
            ),
            $namaFile
        );
    }

    /**
     * Query dasar gap ABJ: rata-rata ABJ per kecamatan (< 95), urut naik.
     *
     * Dipakai bersama oleh gapAbj() (JSON) dan GapAbjExport (Excel) agar
     * hasilnya selalu konsisten.
     */
    protected function buildGapAbjQuery(?string $dari = null, ?string $sampai = null, ?string $parentKode = null)
    {
        $query = \DB::table('abj_laporan')
            ->join('wilayah', 'wilayah.kode', '=', 'abj_laporan.wilayah_kode')
            ->where('wilayah.tingkat', 'kecamatan')
            ->select(
                'wilayah.kode',
                'wilayah.nama',
                'wilayah.parent_kode',
                \DB::raw('ROUND(AVG(abj_laporan.abj_persen)::numeric, 2) as rata_abj')
            )
            ->groupBy('wilayah.kode', 'wilayah.nama', 'wilayah.parent_kode')
            ->havingRaw('AVG(abj_laporan.abj_persen) < 95')
            ->orderBy('rata_abj');

        if ($dari) {
            $query->where('abj_laporan.tanggal_pemeriksaan', '>=', $dari);
        }

        if ($sampai) {
            $query->where('abj_laporan.tanggal_pemeriksaan', '<=', $sampai);
        }

        if ($parentKode) {
            $query->where('wilayah.parent_kode', $parentKode);
        }

        return $query;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * LONJAKAN RISIKO — deteksi kenaikan risiko tercepat (7 hari)
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * GET /api/statistik/lonjakan-risiko?jenis=dbd
     *
     * Deteksi lonjakan risiko: kecamatan dengan kenaikan skor risiko
     * tercepat dalam 7 hari terakhir.
     *
     * Untuk setiap kecamatan:
     * - skor_sekarang     = skor risiko TERBARU (tanggal terakhir yang tersedia).
     * - skor_7_hari_lalu  = skor risiko pada tanggal TERAKHIR yang ≤ 7 hari lalu
     *                       (tanggal terdekat dalam jendela 7–10 hari ke belakang).
     * - delta             = skor_sekarang − skor_7_hari_lalu.
     *
     * Hanya kecamatan dengan delta > 0 (risiko naik) yang disertakan, diurutkan
     * dari kenaikan terbesar ke terkecil, dibatasi 15 baris. Setiap baris juga
     * membawa riwayat_7_hari (tanggal + skor) agar frontend bisa menggambar
     * sparkline tanpa panggilan API tambahan.
     */
    public function lonjakanRisiko(Request $request)
    {
        $request->validate([
            'jenis' => 'nullable|in:dbd,malaria',
        ]);

        $jenis = $request->filled('jenis') ? $request->jenis : 'dbd';

        $hariIni    = now();
        $tujuhLalu  = $hariIni->copy()->subDays(7);
        $batasBawah = $hariIni->copy()->subDays(10);

        // Semua skor risiko 10 hari terakhir dalam 1 query, lalu dikelompokkan
        // per wilayah di PHP (hindari N+1 per kecamatan).
        $skorRows = SkorRisiko::where('jenis_penyakit', $jenis)
            ->where('is_prediksi', false)
            ->whereBetween('tanggal', [$batasBawah->toDateString(), $hariIni->toDateString()])
            ->with('wilayah:kode,nama')
            ->orderBy('tanggal')
            ->get(['wilayah_kode', 'tanggal', 'skor']);

        $perWilayah = $skorRows->groupBy('wilayah_kode');

        $hasil = [];

        foreach ($perWilayah as $kode => $rows) {
            $rows = $rows->sortBy('tanggal')->values();

            $terbaru = $rows->last();
            $skorSekarang = (float) $terbaru->skor;

            // Skor baseline: tanggal TERAKHIR yang ≤ 7 hari lalu (paling dekat ke 7 hari).
            $baseline = $rows
                ->filter(fn($r) => $r->tanggal->lte($tujuhLalu->copy()->endOfDay()))
                ->last();

            if ($baseline === null) {
                continue;
            }

            $skor7 = (float) $baseline->skor;
            $delta = round($skorSekarang - $skor7, 1);

            // Hanya kenaikan positif (delta > 0).
            if ($delta <= 0) {
                continue;
            }

            $riwayat = $rows
                ->filter(fn($r) => $r->tanggal->gte($baseline->tanggal->copy()->startOfDay()))
                ->map(fn($r) => [
                    'tanggal' => $r->tanggal->toDateString(),
                    'skor'    => (float) $r->skor,
                ])
                ->values();

            $hasil[] = [
                'kode'             => $kode,
                'nama'             => $terbaru->wilayah?->nama ?? '?',
                'skor_sekarang'    => $skorSekarang,
                'skor_7_hari_lalu' => $skor7,
                'delta'            => $delta,
                'riwayat_7_hari'   => $riwayat,
            ];
        }

        // Kenaikan terbesar di depan, batasi 15 hasil.
        usort($hasil, fn($a, $b) => $b['delta'] <=> $a['delta']);
        $hasil = array_slice($hasil, 0, 15);

        return response()->json(['data' => array_values($hasil)]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * LAPORAN RINGKAS OTOMATIS — narasi ringkas berbasis template teks
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * GET /api/statistik/laporan-ringkas/{kode}
     *
     * Membuat laporan ringkas naratif otomatis untuk sebuah wilayah (kecamatan).
     * Narasi dihasilkan dari template teks yang diisi dari data riil, bukan AI.
     */
    public function laporanRingkas($kode, Request $request)
    {
        $request->validate([
            'dari'   => 'nullable|date',
            'sampai' => 'nullable|date',
            'jenis'  => 'nullable|in:dbd,malaria',
        ]);

        $wilayah = Wilayah::find($kode);

        if (!$wilayah) {
            return response()->json(['message' => 'Wilayah tidak ditemukan.'], 404);
        }

        $jenis  = $request->filled('jenis') ? $request->jenis : 'dbd';
        $dari   = $request->filled('dari') ? $request->dari : null;
        $sampai = $request->filled('sampai') ? $request->sampai : null;

        $data = $this->buildLaporanRingkasData($wilayah, $jenis, $dari, $sampai);

        return response()->json([
            'narasi'    => $data['narasi'],
            'ringkasan' => $data['ringkasan'],
            'wilayah'   => $wilayah->only(['kode', 'nama', 'tingkat']),
        ]);
    }

    /**
     * GET /api/statistik/laporan-ringkas/{kode}/pdf
     *
     * Sama seperti laporanRingkas(), tetapi dirender ke PDF via DomPDF.
     */
    public function laporanRingkasPdf($kode, Request $request)
    {
        $request->validate([
            'dari'   => 'nullable|date',
            'sampai' => 'nullable|date',
            'jenis'  => 'nullable|in:dbd,malaria',
        ]);

        $wilayah = Wilayah::find($kode);

        if (!$wilayah) {
            abort(404, 'Wilayah tidak ditemukan.');
        }

        $jenis  = $request->filled('jenis') ? $request->jenis : 'dbd';
        $dari   = $request->filled('dari') ? $request->dari : null;
        $sampai = $request->filled('sampai') ? $request->sampai : null;

        $data = $this->buildLaporanRingkasData($wilayah, $jenis, $dari, $sampai);

        $jenisLabel = $jenis === 'malaria' ? 'Malaria' : 'DBD';
        $periode = $dari && $sampai
            ? "{$dari} s/d {$sampai}"
            : ($dari ? "dari {$dari}" : ($sampai ? "hingga {$sampai}" : 'Semua data'));

        $namaFile = 'laporan-ringkas-' . str_replace(' ', '-', strtolower($wilayah->nama))
            . '-' . now()->format('Ymd') . '.pdf';

        $pdf = Pdf::loadView('exports.laporan-ringkas-pdf', [
            'wilayah'   => $wilayah,
            'jenis'     => $jenisLabel,
            'periode'   => $periode,
            'narasi'    => $data['narasi'],
            'ringkasan' => $data['ringkasan'],
        ]);

        return $pdf->download($namaFile);
    }

    /**
     * Bangun data + narasi laporan ringkas untuk satu wilayah.
     *
     * Mengambil skor risiko terbaru, skor 7 hari lalu (deteksi perubahan),
     * rata-rata ABJ, curah hujan tertinggi, total laporan warga, dan tren skor
     * harian dalam rentang tanggal yang diminta.
     */
    protected function buildLaporanRingkasData(Wilayah $wilayah, string $jenis, ?string $dari, ?string $sampai): array
    {
        $kode = $wilayah->kode;

        // ── Skor risiko terbaru (dalam rentang tanggal jika ada) ──
        $skorQuery = SkorRisiko::where('wilayah_kode', $kode)
            ->where('jenis_penyakit', $jenis)
            ->where('is_prediksi', false);
        $this->applyTanggalRange($skorQuery, $dari, $sampai, 'tanggal', false);
        $skorTerkini = $skorQuery->orderByDesc('tanggal')->first();

        // ── Skor risiko 7 hari sebelum skor terbaru (deteksi perubahan) ──
        $skor7HariLalu = null;
        if ($skorTerkini) {
            $batas7Hari = $skorTerkini->tanggal->copy()->subDays(7)->toDateString();
            $skor7HariLalu = SkorRisiko::where('wilayah_kode', $kode)
                ->where('jenis_penyakit', $jenis)
                ->where('is_prediksi', false)
                ->where('tanggal', '<=', $batas7Hari)
                ->orderByDesc('tanggal')
                ->first();
        }

        // ── Rata-rata ABJ dalam rentang tanggal ──
        $abjQuery = AbjLaporan::where('wilayah_kode', $kode);
        if ($dari)   $abjQuery->where('tanggal_pemeriksaan', '>=', $dari);
        if ($sampai) $abjQuery->where('tanggal_pemeriksaan', '<=', $sampai);
        $abjRows = $abjQuery->get(['tanggal_pemeriksaan', 'abj_persen']);
        $rataAbj = $abjRows->isNotEmpty() ? round($abjRows->avg('abj_persen'), 2) : null;

        // ── Curah hujan tertinggi dalam rentang (historis, bukan forecast) ──
        $cuacaQuery = DataCuaca::where('wilayah_kode', $kode)->where('is_forecast', false);
        if ($dari)   $cuacaQuery->where('tanggal', '>=', $dari);
        if ($sampai) $cuacaQuery->where('tanggal', '<=', $sampai);
        $hujanTertinggi = $cuacaQuery->orderByDesc('curah_hujan')->first();

        // ── Total laporan warga dalam rentang ──
        $laporanQuery = LaporanWarga::where('wilayah_kode', $kode);
        if ($dari)   $laporanQuery->whereDate('created_at', '>=', $dari);
        if ($sampai) $laporanQuery->whereDate('created_at', '<=', $sampai);
        $totalLaporan = $laporanQuery->count();

        // ── Tren skor risiko per hari ──
        $trenSkorQuery = SkorRisiko::where('wilayah_kode', $kode)
            ->where('jenis_penyakit', $jenis)
            ->where('is_prediksi', false);
        $this->applyTanggalRange($trenSkorQuery, $dari, $sampai, 'tanggal', false);
        $trenSkor = $trenSkorQuery->orderBy('tanggal')
            ->get(['tanggal', 'skor', 'level_risiko'])
            ->map(fn($r) => [
                'tanggal'      => $r->tanggal->toDateString(),
                'skor'         => $r->skor !== null ? (float) $r->skor : null,
                'level_risiko' => $r->level_risiko,
            ])
            ->values();

        // ── Narasi otomatis ──
        $narasi = $this->susunNarasi(
            $wilayah, $jenis, $skorTerkini, $skor7HariLalu, $rataAbj, $abjRows, $hujanTertinggi, $totalLaporan
        );

        // ── Ringkasan terstruktur (untuk PDF & frontend) ──
        $delta = null;
        if ($skorTerkini && $skor7HariLalu) {
            $delta = round((float) $skorTerkini->skor - (float) $skor7HariLalu->skor, 1);
        }

        return [
            'narasi'    => $narasi,
            'ringkasan' => [
                'skor'                  => $skorTerkini ? (float) $skorTerkini->skor : null,
                'level'                 => $skorTerkini?->level_risiko,
                'tanggal'               => $skorTerkini ? $skorTerkini->tanggal->toDateString() : null,
                'skor_7_hari_lalu'      => $skor7HariLalu ? (float) $skor7HariLalu->skor : null,
                'level_7_hari_lalu'     => $skor7HariLalu?->level_risiko,
                'delta'                 => $delta,
                'abj'                   => $rataAbj,
                'curah_hujan_tertinggi' => $hujanTertinggi ? (float) $hujanTertinggi->curah_hujan : null,
                'tanggal_curah_hujan'   => $hujanTertinggi ? $hujanTertinggi->tanggal->toDateString() : null,
                'total_laporan'         => (int) $totalLaporan,
                'tren_skor'             => $trenSkor,
            ],
        ];
    }

    /**
     * Susun narasi laporan ringkas dari data riil.
     */
    protected function susunNarasi(
        Wilayah $wilayah,
        string $jenis,
        $skorTerkini,
        $skor7HariLalu,
        ?float $rataAbj,
        $abjRows,
        $hujanTertinggi,
        int $totalLaporan
    ): string {
        $sebutan    = ucfirst($wilayah->tingkat); // "Kecamatan" / "Kabupaten"
        $jenisLabel = $jenis === 'malaria' ? 'Malaria' : 'DBD';
        $levelValue = ['rendah' => 1, 'sedang' => 2, 'tinggi' => 3];

        // Status risiko: kenaikan / penurunan / stabil
        $statusRisiko = 'kondisi risiko yang stabil';
        $levelSekarang = $skorTerkini?->level_risiko;
        $levelDulu     = $skor7HariLalu?->level_risiko;
        if ($levelSekarang && $levelDulu) {
            $nowVal = $levelValue[$levelSekarang] ?? 0;
            $oldVal = $levelValue[$levelDulu] ?? 0;
            if ($nowVal > $oldVal) {
                $statusRisiko = 'kenaikan risiko';
            } elseif ($nowVal < $oldVal) {
                $statusRisiko = 'penurunan risiko';
            }
        }

        // 1. Kalimat pembuka
        if ($skorTerkini) {
            $kalimatPembuka = "{$sebutan} {$wilayah->nama} mengalami {$statusRisiko} dengan skor risiko {$jenisLabel} sebesar "
                . round((float) $skorTerkini->skor, 1) . '/100 pada tanggal '
                . $skorTerkini->tanggal->format('d-m-Y') . '.';
        } else {
            $kalimatPembuka = "{$sebutan} {$wilayah->nama} belum memiliki data skor risiko {$jenisLabel} pada periode ini.";
        }

        // 2. Perubahan dibanding 7 hari lalu
        if ($skorTerkini && $skor7HariLalu) {
            $delta = round((float) $skorTerkini->skor - (float) $skor7HariLalu->skor, 1);
            $arah  = $delta > 0 ? 'meningkat' : ($delta < 0 ? 'menurun' : 'tetap');
            $kalimatPerubahan = 'Dibandingkan ' . $skor7HariLalu->tanggal->format('d-m-Y')
                . ' (skor ' . round((float) $skor7HariLalu->skor, 1) . '), skor risiko ' . $arah
                . ' sebesar ' . abs($delta) . ' poin.';
        } elseif ($skorTerkini) {
            $kalimatPerubahan = 'Belum ada data skor risiko 7 hari sebelumnya untuk perbandingan tren.';
        } else {
            $kalimatPerubahan = '';
        }

        // 3. Rata-rata ABJ vs target nasional 95%
        if ($abjRows->isNotEmpty() && $rataAbj !== null) {
            $statusAbj = $rataAbj >= 95 ? 'telah mencapai target' : 'masih di bawah target';
            $kalimatAbj = "Rata-rata ABJ tercatat {$rataAbj}%, {$statusAbj} dari target nasional 95%.";
        } else {
            $kalimatAbj = 'Belum ada data ABJ pada periode ini.';
        }

        // 4. Curah hujan tertinggi
        if ($hujanTertinggi) {
            $ch = (float) $hujanTertinggi->curah_hujan;
            if ($ch >= 100) {
                $dampakHujan = 'peningkatan signifikan potensi tempat perindukan nyamuk dan risiko penularan penyakit';
            } elseif ($ch >= 50) {
                $dampakHujan = 'peningkatan potensi tempat perindukan nyamuk';
            } elseif ($ch >= 20) {
                $dampakHujan = 'potensi genangan air yang dapat menjadi tempat perindukan nyamuk';
            } else {
                $dampakHujan = 'risiko genangan air yang relatif rendah';
            }
            $kalimatHujan = "Curah hujan tertinggi tercatat {$ch}mm pada tanggal "
                . $hujanTertinggi->tanggal->format('d-m-Y') . ", yang berkontribusi pada {$dampakHujan}.";
        } else {
            $kalimatHujan = 'Data curah hujan pada periode ini belum tersedia.';
        }

        // 5. Total laporan warga
        $kalimatLaporan = "Terdapat {$totalLaporan} laporan warga dalam periode ini.";

        // 6. Rekomendasi berdasarkan level risiko + ABJ
        $levelTinggi = $levelSekarang === 'tinggi';
        $levelSedang = $levelSekarang === 'sedang';
        $abjBaik     = $rataAbj !== null && $rataAbj >= 95;

        if ($levelTinggi) {
            $rekomendasi = $abjBaik
                ? 'peningkatan kewaspadaan dan surveilans aktif, meskipun ABJ sudah mencapai target, karena risiko masih tinggi.'
                : 'intensifikasi Pemberantasan Sarang Nyamuk (PSN), peningkatan surveilans, serta pertimbangan fogging fokus di area dengan ABJ rendah.';
        } elseif ($levelSedang) {
            $rekomendasi = $abjBaik
                ? 'pemantauan rutin dan pertahankan ABJ agar tidak turun di bawah target nasional.'
                : 'peningkatan kegiatan PSN dan edukasi masyarakat untuk menekan angka bebas jentik.';
        } else {
            $rekomendasi = $abjBaik
                ? 'pertahankan kondisi yang baik dan lanjutkan pemantauan rutin.'
                : 'perbaikan angka bebas jentik melalui PSN rutin agar risiko tidak meningkat.';
        }

        $bagian = array_filter([
            $kalimatPembuka,
            $kalimatPerubahan,
            $kalimatAbj,
            $kalimatHujan,
            $kalimatLaporan,
            "Direkomendasikan {$rekomendasi}",
        ]);

        return trim(implode(' ', $bagian));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * DATASET RISET — unduh multi-sheet (Feature 8)
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * GET /api/statistik/export-riset
     *
     * Unduh dataset riset sebagai Excel/CSV.
     *
     * - wilayah_kode  : kode wilayah (opsional → semua wilayah).
     * - dari / sampai : rentang tanggal (opsional).
     * - jenis_data[]  : skor_risiko | data_abj | laporan_warga | data_cuaca.
     * - format        : csv | xlsx (default xlsx).
     */
    public function exportRiset(Request $request)
    {
        $request->validate([
            'wilayah_kode' => 'nullable|exists:wilayah,kode',
            'dari'         => 'nullable|date',
            'sampai'       => 'nullable|date',
            'jenis_data'   => 'nullable|array',
            'jenis_data.*' => 'in:skor_risiko,data_abj,laporan_warga,data_cuaca',
            'format'       => 'nullable|in:csv,xlsx',
        ]);

        $format   = $request->filled('format') ? $request->format : 'xlsx';
        $namaFile = 'dataset-riset-lensajentik-' . now()->format('Ymd') . '.' . $format;

        $export = new RisetExport(
            $request->filled('wilayah_kode') ? $request->wilayah_kode : null,
            $request->filled('dari') ? $request->dari : null,
            $request->filled('sampai') ? $request->sampai : null,
            $request->input('jenis_data', [])
        );

        if ($format === 'csv') {
            return Excel::download($export, $namaFile, \Maatwebsite\Excel\Excel::CSV);
        }

        return Excel::download($export, $namaFile);
    }
}
