<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use App\Models\SkorRisiko;
use App\Models\PrediksiRisiko;
use App\Models\DataCuaca;
use App\Services\RiskScoreService;
use App\Services\SkorCuacaCalculator;
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
     *
     * Detail skor risiko 1 wilayah + prediksi 14 hari ke depan.
     *
     * Untuk kecamatan/desa:
     *   - Fetch cuaca Open-Meteo & kalkulasi skor (on-demand hydration)
     *   - Return data langsung dari skor_risiko + prediksi_risiko
     *
     * Untuk kabupaten/kota & provinsi:
     *   - Agregasi dari seluruh kecamatan anak (rata-rata skor, suhu, kelembapan, dll)
     *   - Data TIDAK akan kosong selama ada data di level kecamatan
     */
    public function show(Request $request, string $kode)
    {
        $jenis = $request->query('jenis', 'dbd');

        $wilayah = Wilayah::find($kode);

        if (!$wilayah) {
            return response()->json(['message' => 'Wilayah tidak ditemukan'], 404);
        }

        // ── Level kecamatan: hitung & simpan langsung ─────────────────
        if (!$wilayah->isAdministrativeLevel()) {
            // Untuk desa, naikkan ke parent kecamatan
            $target = $wilayah;
            if ($wilayah->tingkat === 'desa') {
                $target = Wilayah::where('kode', $wilayah->parent_kode)
                    ->where('tingkat', 'kecamatan')
                    ->first();
                if (!$target) {
                    return response()->json(['message' => 'Parent kecamatan tidak ditemukan'], 404);
                }
            }

            $hasil = $this->riskScoreService->hitungDanSimpan($target, $jenis);

            return response()->json([
                'wilayah'        => $wilayah->only(['kode', 'nama', 'tingkat', 'latitude', 'longitude']),
                'jenis_penyakit' => $jenis,
                'skor_hari_ini'  => $hasil['historis'][0] ?? null,
                'prediksi'       => $hasil['prediksi'],
            ]);
        }

        // ── Level kabupaten/provinsi: AGREGASI dari kecamatan anak ────
        $kecamatanCodes = $wilayah->getAllKecamatanCodes();

        if (empty($kecamatanCodes)) {
            return response()->json([
                'wilayah'        => $wilayah->only(['kode', 'nama', 'tingkat', 'latitude', 'longitude']),
                'jenis_penyakit' => $jenis,
                'skor_hari_ini'  => null,
                'prediksi'       => [],
                'indikator_cuaca'=> null,
                'message'        => 'Tidak ada data kecamatan di wilayah ini.',
            ]);
        }

        // ── 1. Agregasi skor risiko hari ini ─────────────────────────
        $skorHariIni = $this->agregasiSkorHariIni($kecamatanCodes, $jenis);

        // ── 2. Agregasi prediksi 14 hari ke depan ────────────────────
        $prediksi = $this->agregasiPrediksi($kecamatanCodes, $jenis);

        // ── 3. Agregasi indikator cuaca dari data_cuaca ──────────────
        $indikatorCuaca = $this->agregasiCuacaTerkini($kecamatanCodes);

        // ── 4. Agregasi ABJ dari semua kecamatan ─────────────────────
        $abjAgregat = $this->agregasiAbj($kecamatanCodes);

        return response()->json([
            'wilayah'          => $wilayah->only(['kode', 'nama', 'tingkat', 'latitude', 'longitude', 'elevasi']),
            'jenis_penyakit'   => $jenis,
            'jumlah_kecamatan' => count($kecamatanCodes),
            'skor_hari_ini'    => $skorHariIni,
            'prediksi'         => $prediksi,
            'indikator_cuaca'  => $indikatorCuaca,
            'abj_agregat'      => $abjAgregat,
        ]);
    }

    /**
     * Agregasi skor risiko hari ini dari seluruh kecamatan.
     */
    protected function agregasiSkorHariIni(array $kecamatanCodes, string $jenis): ?array
    {
        $today = now()->timezone('Asia/Jakarta')->toDateString();

        $skorList = SkorRisiko::whereIn('wilayah_kode', $kecamatanCodes)
            ->where('jenis_penyakit', $jenis)
            ->where('is_prediksi', false)
            ->whereDate('tanggal', $today)
            ->get();

        if ($skorList->isEmpty()) {
            // Fallback: ambil data terbaru yang ada (tidak harus hari ini)
            $subQuery = SkorRisiko::selectRaw('wilayah_kode, MAX(tanggal) as max_tanggal')
                ->whereIn('wilayah_kode', $kecamatanCodes)
                ->where('jenis_penyakit', $jenis)
                ->where('is_prediksi', false)
                ->groupBy('wilayah_kode');

            $latestDates = DB::query()
                ->fromSub($subQuery, 'latest')
                ->pluck('max_tanggal', 'wilayah_kode')
                ->toArray();

            if (empty($latestDates)) {
                return null;
            }

            $skorList = collect();
            foreach ($latestDates as $kodeKec => $tanggal) {
                $skor = SkorRisiko::where('wilayah_kode', $kodeKec)
                    ->where('jenis_penyakit', $jenis)
                    ->where('is_prediksi', false)
                    ->whereDate('tanggal', $tanggal)
                    ->first();
                if ($skor) $skorList->push($skor);
            }
        }

        if ($skorList->isEmpty()) {
            return null;
        }

        $avgSkor = round($skorList->avg('skor'), 2);
        $level = SkorCuacaCalculator::tentukanLevel($avgSkor);

        // Confidence: kuat jika mayoritas kecamatan punya data ABJ
        $kuatCount = $skorList->where('confidence_level', 'kuat')->count();
        $confidence = $kuatCount >= count($skorList) / 2 ? 'kuat' : 'lemah';

        // Agregasi faktor perhitungan
        $faktorAgregat = [
            'skor_agregat'          => $avgSkor,
            'jumlah_kecamatan'      => count($kecamatanCodes),
            'kecamatan_dengan_data' => $skorList->count(),
            'metode'                => 'rata-rata skor dari seluruh kecamatan',
            'skor_min'              => round($skorList->min('skor'), 2),
            'skor_max'              => round($skorList->max('skor'), 2),
        ];

        return [
            'skor'               => $avgSkor,
            'level_risiko'       => $level,
            'confidence_level'   => $confidence,
            'faktor_perhitungan' => $faktorAgregat,
            'tanggal'            => $today,
            'is_prediksi'        => false,
            'is_agregat'         => true,
        ];
    }

    /**
     * Agregasi prediksi 14 hari dari seluruh kecamatan.
     */
    protected function agregasiPrediksi(array $kecamatanCodes, string $jenis): array
    {
        $today = now()->timezone('Asia/Jakarta')->toDateString();

        $prediksiList = PrediksiRisiko::whereIn('wilayah_kode', $kecamatanCodes)
            ->where('jenis_penyakit', $jenis)
            ->where('tanggal_prediksi', '>=', $today)
            ->orderBy('tanggal_prediksi')
            ->get()
            ->groupBy('tanggal_prediksi');

        $result = [];
        foreach ($prediksiList as $tanggal => $items) {
            $avgSkor = round($items->avg('skor'), 2);
            $level = SkorCuacaCalculator::tentukanLevel($avgSkor);
            $kuatCount = $items->where('confidence_level', 'kuat')->count();
            $confidence = $kuatCount >= count($items) / 2 ? 'kuat' : 'lemah';

            $result[] = [
                'tanggal_prediksi'    => $tanggal,
                'skor'                => $avgSkor,
                'level_risiko'        => $level,
                'confidence_level'    => $confidence,
                'jumlah_kecamatan'    => count($items),
                'is_agregat'          => true,
            ];
        }

        return $result;
    }

    /**
     * Agregasi indikator cuaca terkini dari data_cuaca seluruh kecamatan.
     * Return rata-rata suhu, kelembapan, dan total curah hujan.
     */
    protected function agregasiCuacaTerkini(array $kecamatanCodes): ?array
    {
        $today = now()->timezone('Asia/Jakarta')->toDateString();

        $cuacaList = DataCuaca::whereIn('wilayah_kode', $kecamatanCodes)
            ->where('tanggal', $today)
            ->where('is_forecast', false)
            ->get();

        if ($cuacaList->isEmpty()) {
            // Fallback: ambil data terbaru
            $latestDate = DataCuaca::whereIn('wilayah_kode', $kecamatanCodes)
                ->where('is_forecast', false)
                ->max('tanggal');

            if (!$latestDate) {
                return null;
            }

            $cuacaList = DataCuaca::whereIn('wilayah_kode', $kecamatanCodes)
                ->where('tanggal', $latestDate)
                ->where('is_forecast', false)
                ->get();
        }

        if ($cuacaList->isEmpty()) {
            return null;
        }

        $avgSuhu = round($cuacaList->avg('suhu_avg'), 1);
        $avgKelembapan = round($cuacaList->avg('kelembapan_avg'), 1);
        $totalHujan = round($cuacaList->sum('curah_hujan'), 1);
        $rataHujan = round($cuacaList->avg('curah_hujan'), 1);

        return [
            'suhu_avg'             => $avgSuhu,
            'kelembapan_avg'       => $avgKelembapan,
            'curah_hujan_total'    => $totalHujan,
            'curah_hujan_rata'     => $rataHujan,
            'jumlah_kecamatan'     => count($kecamatanCodes),
            'kecamatan_dengan_data'=> $cuacaList->count(),
            'metode'               => 'rata-rata dari data cuaca seluruh kecamatan',
            'tanggal'              => $cuacaList->first()->tanggal ?? null,
        ];
    }

    /**
     * Agregasi ABJ dari seluruh kecamatan.
     */
    protected function agregasiAbj(array $kecamatanCodes): ?array
    {
        $batas = now()->subDays(30);

        $abjData = DB::table('abj_laporan')
            ->whereIn('wilayah_kode', $kecamatanCodes)
            ->where('tanggal_pemeriksaan', '>=', $batas)
            ->selectRaw('
                ROUND(AVG(abj_persen), 2) as rata_abj,
                SUM(jumlah_rumah_diperiksa) as total_diperiksa,
                SUM(jumlah_rumah_positif) as total_positif,
                COUNT(*) as jumlah_laporan
            ')
            ->first();

        if (!$abjData || !$abjData->rata_abj) {
            return null;
        }

        return [
            'rata_abj_persen'   => (float) $abjData->rata_abj,
            'total_diperiksa'   => (int) $abjData->total_diperiksa,
            'total_positif'     => (int) $abjData->total_positif,
            'jumlah_laporan'    => (int) $abjData->jumlah_laporan,
            'periode'           => '30 hari terakhir',
            'jumlah_kecamatan'  => count($kecamatanCodes),
            'metode'            => 'rata-rata ABJ dari seluruh kecamatan',
        ];
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
            'level_risiko' => 'nullable|in:rendah,sedang,tinggi,belum_ada_data',
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

                $data = $this->querySkorKecamatanPerKabupaten($kodeList, $jenis);

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
        $aggregated = $this->agregasiPeta($tingkat, $jenis, $tanggal, $isPrediksi, $request->parent_kode);

        // Tentukan level_risiko berdasarkan skor rata-rata
        $data = $aggregated->map(function ($row) use ($jenis, $tanggal, $isPrediksi, $tingkat) {
            $hasData = $row->skor !== null;
            $skor = $hasData ? (float) $row->skor : 0;
            $level = $hasData
                ? ($skor >= 70 ? 'tinggi' : ($skor >= 40 ? 'sedang' : 'rendah'))
                : 'belum_ada_data';
            $confidence = $hasData ? 'lemah' : 'belum_ada_data';

            return [
                'wilayah_kode'       => $row->parent_kode,
                'jenis_penyakit'     => $jenis,
                'tanggal'            => $tanggal,
                'is_prediksi'        => $isPrediksi,
                'skor'               => $skor,
                'level_risiko'       => $level,
                'confidence_level'   => $confidence,
                'faktor_perhitungan' => [
                    'skor_agregat'           => $skor,
                    'jumlah_kecamatan'       => $row->jumlah_kecamatan ?? 0,
                    'kecamatan_dengan_data'  => $row->kecamatan_dengan_data ?? 0,
                    'catatan'                => $hasData
                        ? 'Skor rata-rata dari skor cuaca seluruh kecamatan'
                        : 'Belum ada data skor risiko. Jalankan skor-risiko:refresh-cuaca untuk generate data.',
                ],
                'wilayah' => [
                    'kode'      => $row->parent_kode,
                    'nama'      => $row->parent_nama,
                    'latitude'  => $row->latitude,
                    'longitude' => $row->longitude,
                    'tingkat'   => $row->tingkat ?? $tingkat,
                ],
            ];
        });

        // Filter level_risiko jika diminta (pakai $tingkat dari parameter method)
        if ($request->filled('level_risiko')) {
            $data = $data->where('level_risiko', $request->level_risiko);
        }

        return response()->json(['data' => $data->values()]);
    }

    protected function querySkorKecamatanPerKabupaten(array $kodeList, string $jenis)
    {
        $placeholders = implode(',', array_fill(0, count($kodeList), '?'));

        $rows = DB::select("
            SELECT
                w.kode, w.nama, w.latitude, w.longitude,
                sr.skor, sr.level_risiko, sr.confidence_level,
                sr.faktor_perhitungan, sr.tanggal
            FROM wilayah w
            LEFT JOIN LATERAL (
                SELECT wilayah_kode, skor, level_risiko, confidence_level, faktor_perhitungan, tanggal
                FROM skor_risiko
                WHERE wilayah_kode = w.kode
                  AND jenis_penyakit = ?
                  AND is_prediksi = false
                ORDER BY tanggal DESC
                LIMIT 1
            ) sr ON true
            WHERE w.kode IN ({$placeholders})
            ORDER BY w.nama
        ", array_merge([$jenis], $kodeList));

        return collect($rows)->map(fn($w) => [
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
    }

    /**
     * Agregasi skor untuk peta level kabupaten/provinsi.
     */
    protected function agregasiPeta(string $tingkat, string $jenis, string $tanggal, bool $isPrediksi, ?string $parentKode)
    {
        if ($tingkat === 'provinsi') {
            $builder = DB::table('wilayah as prov')
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

            if ($parentKode) {
                $builder->where('prov.parent_kode', '=', $parentKode);
            }

            $builder->groupBy('prov.kode', 'prov.nama', 'prov.latitude', 'prov.longitude')
                ->select(
                    'prov.kode as parent_kode',
                    'prov.nama as parent_nama',
                    'prov.latitude',
                    'prov.longitude',
                    DB::raw("'provinsi' as tingkat"),
                    DB::raw('ROUND(AVG(sr.skor), 1) as skor'),
                    DB::raw('COUNT(DISTINCT kab.kode) as jumlah_kabupaten'),
                    DB::raw('COUNT(DISTINCT kec.kode) as jumlah_kecamatan'),
                    DB::raw('COUNT(DISTINCT sr.wilayah_kode) as kecamatan_dengan_data')
                );
        } else {
            $builder = DB::table('wilayah as parent')
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

            if ($parentKode) {
                $builder->where('parent.parent_kode', '=', $parentKode);
            }

            $builder->groupBy('parent.kode', 'parent.nama', 'parent.latitude', 'parent.longitude')
                ->select(
                    'parent.kode as parent_kode',
                    'parent.nama as parent_nama',
                    'parent.latitude',
                    'parent.longitude',
                    DB::raw("'{$tingkat}' as tingkat"),
                    DB::raw('ROUND(COALESCE(AVG(sr.skor), AVG(sr_parent.skor)), 1) as skor'),
                    DB::raw('COUNT(DISTINCT child.kode) as jumlah_kecamatan'),
                    DB::raw('COUNT(DISTINCT sr.wilayah_kode) as kecamatan_dengan_data')
                );
        }

        return $builder->get();
    }
}
