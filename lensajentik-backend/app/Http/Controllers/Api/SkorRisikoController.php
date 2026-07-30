<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use App\Models\SkorRisiko;
use App\Services\RiskScoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SkorRisikoController extends Controller
{
    public function __construct(
        protected RiskScoreService $riskScoreService
    ) {}

    /**
     * POST /api/skor-risiko/refresh-kabupaten?parent_kode=3201
     * Refresh skor risiko untuk SELURUH kecamatan dalam satu kabupaten.
     */
    public function refreshKabupaten(Request $request)
    {
        $request->validate([
            'parent_kode' => 'required|exists:wilayah,kode',
        ]);

        $jenis = $request->query('jenis', 'dbd');
        $kecamatan = Wilayah::where('parent_kode', $request->parent_kode)
            ->where('tingkat', 'kecamatan')
            ->get();

        if ($kecamatan->isEmpty()) {
            return response()->json(['message' => 'Tidak ada kecamatan ditemukan'], 404);
        }

        $diproses = 0;
        $gagal = 0;

        foreach ($kecamatan as $kec) {
            try {
                $this->riskScoreService->hitungDanSimpan($kec, $jenis);
                $diproses++;
            } catch (\Exception $e) {
                $gagal++;
                logger()->warning("Refresh kabupaten gagal: {$kec->nama} — {$e->getMessage()}");
            }
        }

        return response()->json([
            'message' => "Selesai: {$diproses} berhasil, {$gagal} gagal dari {$kecamatan->count()} kecamatan",
            'diproses' => $diproses,
            'gagal' => $gagal,
        ]);
    }

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

        // Hitung skor (fetch cuaca + kalkulasi + simpan)
        $hasil = $this->riskScoreService->hitungDanSimpan($wilayah, $jenis);

        return response()->json([
            'wilayah' => $wilayah->only(['kode', 'nama', 'tingkat', 'latitude', 'longitude']),
            'jenis_penyakit' => $jenis,
            'skor_hari_ini' => $hasil['historis'][0] ?? null,
            'prediksi' => $hasil['prediksi'],
        ]);
    }

    /**
     * GET /api/skor-risiko/peta?tingkat=kabupaten&jenis=dbd
     * GET /api/skor-risiko/peta?tingkat=kecamatan&parent_kode=3201&jenis=dbd
     *
     * Untuk render peta: skor risiko hari ini.
     * - Jika parent_kode diberikan: semua wilayah dalam 1 area (misal semua kecamatan di 1 kabupaten).
     * - Jika parent_kode TIDAK diberikan: SEMUA wilayah pada tingkat tersebut (skala nasional).
     *   Contoh: ?tingkat=kabupaten → seluruh kabupaten/kota di Indonesia.
     *
     * Strategi query:
     * - kecamatan/desa: query langsung dari skor_risiko (data sudah ada per wilayah).
     * - kabupaten/provinsi: agregasi dari skor_risiko kecamatan anak (AVG skor),
     *   karena skor_risiko hanya di-generate di tingkat kecamatan.
     */
    public function peta(Request $request)
    {
        $request->validate([
            'tingkat' => 'required|in:provinsi,kabupaten,kecamatan,desa',
            'parent_kode' => 'nullable|string',
            'level_risiko' => 'nullable|in:rendah,sedang,tinggi',
            'tanggal' => 'nullable|date',
        ]);

        $jenis = $request->query('jenis', 'dbd');
        $tanggal = $request->query('tanggal', now()->timezone('Asia/Jakarta')->toDateString());
        $isPrediksi = $tanggal !== now()->timezone('Asia/Jakarta')->toDateString();
        $tingkat = $request->tingkat;

        // ── Level kecamatan/desa: query langsung ───────────────────────────
        if (in_array($tingkat, ['kecamatan', 'desa'])) {
            $wilayahQuery = Wilayah::where('tingkat', $tingkat);

            if ($request->filled('parent_kode')) {
                $wilayahQuery->where('parent_kode', $request->parent_kode);
            }

            $wilayahList = $wilayahQuery->pluck('kode');

            // Jika ada parent_kode: tampilkan SEMUA kecamatan + skor terbarunya
            if ($request->filled('parent_kode')) {
                $kodeList = $wilayahList->toArray();

                if (empty($kodeList)) {
                    return response()->json(['data' => [], 'message' => 'Tidak ada kecamatan untuk parent_kode ini.']);
                }

                $placeholders = implode(',', array_fill(0, count($kodeList), '?'));

                // Raw SQL: LEFT JOIN LATERAL dengan DISTINCT ON (PostgreSQL)
                $rows = \DB::select("
                    SELECT
                        w.kode, w.nama, w.latitude, w.longitude,
                        sr.skor, sr.level_risiko, sr.confidence_level,
                        sr.faktor_perhitungan, sr.tanggal
                    FROM wilayah w
                    LEFT JOIN LATERAL (
                        SELECT DISTINCT ON (wilayah_kode)
                            wilayah_kode, skor, level_risiko, confidence_level, faktor_perhitungan, tanggal
                        FROM skor_risiko
                        WHERE wilayah_kode = w.kode
                          AND jenis_penyakit = ?
                          AND is_prediksi = false
                        ORDER BY wilayah_kode, tanggal DESC
                        LIMIT 1
                    ) sr ON true
                    WHERE w.kode IN ({$placeholders})
                    ORDER BY w.nama
                ", array_merge([$jenis], $kodeList));

                $data = collect($rows)->map(fn($w) => [
                    'wilayah_kode'       => $w->kode,
                    'wilayah'            => ['kode' => $w->kode, 'nama' => $w->nama, 'latitude' => $w->latitude, 'longitude' => $w->longitude, 'tingkat' => 'kecamatan'],
                    'jenis_penyakit'     => $jenis,
                    'skor'               => $w->skor !== null ? (float) $w->skor : null,
                    'level_risiko'       => $w->level_risiko ?? 'belum_ada_data',
                    'confidence_level'   => $w->confidence_level ?? 'belum_ada_data',
                    'faktor_perhitungan' => is_string($w->faktor_perhitungan) ? json_decode($w->faktor_perhitungan, true) : $w->faktor_perhitungan,
                    'tanggal'            => $w->tanggal ?? now()->toDateString(),
                    'is_prediksi'        => false,
                ]);

                if ($request->filled('level_risiko')) {
                    $data = $data->where('level_risiko', $request->level_risiko);
                }

                return response()->json(['data' => $data->values()]);
            } else {
                $query = SkorRisiko::whereIn('wilayah_kode', $wilayahList)
                    ->where('jenis_penyakit', $jenis)
                    ->where('is_prediksi', $isPrediksi)
                    ->whereDate('tanggal', $tanggal)
                    ->with('wilayah:kode,nama,latitude,longitude');
            }

            if ($request->filled('level_risiko')) {
                $query->where('level_risiko', $request->level_risiko);
            }

            return response()->json(['data' => $query->get()]);
        }

        // ── Level kabupaten/provinsi: agregasi dari kecamatan ─────────────
        // Bangun query agregasi: untuk setiap parent, rata-ratakan skor
        // kecamatan anak (via rantai parent_kode).
        // Pakai LEFT JOIN agar wilayah TANPA data skor_risiko tetap muncul.
        if ($tingkat === 'provinsi') {
            // provinsi → kabupaten → kecamatan → skor_risiko (2 level join)
            $builder = \DB::table('wilayah as prov')
                ->leftJoin('wilayah as kab', function ($join) {
                    $join->on('kab.parent_kode', '=', 'prov.kode')
                        ->where('kab.tingkat', '=', 'kabupaten');
                })
                ->leftJoin('wilayah as kec', function ($join) {
                    $join->on('kec.parent_kode', '=', 'kab.kode')
                        ->where('kec.tingkat', '=', 'kecamatan');
                })
                ->leftJoin('skor_risiko as sr', function ($join) use ($jenis, $tanggal, $isPrediksi) {
                    $join->on('sr.wilayah_kode', '=', 'kec.kode')
                        ->where('sr.jenis_penyakit', '=', $jenis)
                        ->where('sr.is_prediksi', '=', $isPrediksi)
                        ->whereDate('sr.tanggal', '=', $tanggal);
                })
                ->where('prov.tingkat', '=', 'provinsi');

            if ($request->filled('parent_kode')) {
                $builder->where('prov.parent_kode', '=', $request->parent_kode);
            }

            $builder->groupBy('prov.kode', 'prov.nama', 'prov.latitude', 'prov.longitude')
                ->select(
                    'prov.kode as parent_kode',
                    'prov.nama as parent_nama',
                    'prov.latitude',
                    'prov.longitude',
                    \DB::raw('ROUND(AVG(sr.skor)::numeric, 1) as skor'),
                    \DB::raw('COUNT(DISTINCT kab.kode) as jumlah_kabupaten'),
                    \DB::raw('COUNT(DISTINCT kec.kode) as jumlah_kecamatan'),
                    \DB::raw('COUNT(DISTINCT sr.wilayah_kode) as kecamatan_dengan_data')
                );
        } else {
            // kabupaten → kecamatan → skor_risiko (1 level join)
            $builder = \DB::table('wilayah as parent')
                ->leftJoin('wilayah as child', function ($join) {
                    $join->on('child.parent_kode', '=', 'parent.kode')
                        ->where('child.tingkat', '=', 'kecamatan');
                })
                ->leftJoin('skor_risiko as sr', function ($join) use ($jenis, $tanggal, $isPrediksi) {
                    $join->on('sr.wilayah_kode', '=', 'child.kode')
                        ->where('sr.jenis_penyakit', '=', $jenis)
                        ->where('sr.is_prediksi', '=', $isPrediksi)
                        ->whereDate('sr.tanggal', '=', $tanggal);
                })
                ->leftJoin('skor_risiko as sr_parent', function ($join) use ($jenis, $tanggal, $isPrediksi) {
                    $join->on('sr_parent.wilayah_kode', '=', 'parent.kode')
                        ->where('sr_parent.jenis_penyakit', '=', $jenis)
                        ->where('sr_parent.is_prediksi', '=', $isPrediksi)
                        ->whereDate('sr_parent.tanggal', '=', $tanggal);
                })
                ->where('parent.tingkat', '=', $tingkat);

            if ($request->filled('parent_kode')) {
                $builder->where('parent.parent_kode', '=', $request->parent_kode);
            }

            $builder->groupBy('parent.kode', 'parent.nama', 'parent.latitude', 'parent.longitude')
                ->select(
                    'parent.kode as parent_kode',
                    'parent.nama as parent_nama',
                    'parent.latitude',
                    'parent.longitude',
                    \DB::raw('ROUND(COALESCE(AVG(sr.skor), AVG(sr_parent.skor))::numeric, 1) as skor'),
                    \DB::raw('COUNT(DISTINCT child.kode) as jumlah_kecamatan'),
                    \DB::raw('COUNT(DISTINCT sr.wilayah_kode) as kecamatan_dengan_data')
                );
        }

        $aggregated = $builder->get();

        // Tentukan level_risiko berdasarkan skor rata-rata
        $data = $aggregated->map(function ($row) use ($jenis, $tanggal, $isPrediksi) {
            // Jika tidak ada data skor (LEFT JOIN menghasilkan NULL), tetap tampilkan
            // dengan skor 0 dan status "belum ada data"
            $hasData = $row->skor !== null;
            $skor = $hasData ? (float) $row->skor : 0;
            $level = $hasData
                ? ($skor >= 70 ? 'tinggi' : ($skor >= 40 ? 'sedang' : 'rendah'))
                : 'belum_ada_data';
            $confidence = $hasData ? 'lemah' : 'belum_ada_data';

            return [
                'wilayah_kode' => $row->parent_kode,
                'jenis_penyakit' => $jenis,
                'tanggal' => $tanggal,
                'is_prediksi' => $isPrediksi,
                'skor' => $skor,
                'level_risiko' => $level,
                'confidence_level' => $confidence,
                'faktor_perhitungan' => [
                    'skor_agregat' => $skor,
                    'jumlah_kecamatan' => $row->jumlah_kecamatan ?? 0,
                    'kecamatan_dengan_data' => $row->kecamatan_dengan_data ?? 0,
                    'catatan' => $hasData
                        ? 'Skor rata-rata dari skor cuaca seluruh kecamatan'
                        : 'Belum ada data skor risiko. Jalankan skor-risiko:refresh-cuaca untuk generate data.',
                ],
                'wilayah' => [
                    'kode' => $row->parent_kode,
                    'nama' => $row->parent_nama,
                    'latitude' => $row->latitude,
                    'longitude' => $row->longitude,
                    'tingkat' => $tingkat,
                ],
            ];
        });

        // Filter level_risiko jika diminta
        if ($request->filled('level_risiko')) {
            $data = $data->where('level_risiko', $request->level_risiko);
        }

        return response()->json(['data' => $data->values()]);
    }
}