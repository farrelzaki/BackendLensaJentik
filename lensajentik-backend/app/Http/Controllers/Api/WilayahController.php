<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WilayahController extends Controller
{
    protected string $baseUrl = 'https://emsifa.github.io/api-wilayah-indonesia/api';

    /**
     * List wilayah dengan filter: tingkat & parent_kode
     * GET /api/wilayah?tingkat=provinsi
     * GET /api/wilayah?tingkat=kabupaten&parent_kode=11
     */
    public function index(Request $request)
    {
        $query = Wilayah::query();

        if ($request->filled('tingkat')) {
            $query->where('tingkat', $request->tingkat);
        }

        if ($request->filled('parent_kode')) {
            $query->where('parent_kode', $request->parent_kode);
        }

        $wilayah = $query->orderBy('nama')->get();

        return response()->json(['data' => $wilayah]);
    }

    /**
     * Detail 1 wilayah + parent-nya (breadcrumb)
     * GET /api/wilayah/{kode}
     */
    public function show(string $kode)
    {
        $wilayah = Wilayah::with('parent.parent.parent')->find($kode);

        if (!$wilayah) {
            return response()->json(['message' => 'Wilayah tidak ditemukan'], 404);
        }

        return response()->json(['data' => $wilayah]);
    }

    /**
     * Search wilayah berdasarkan nama (kecamatan & kabupaten)
     * GET /api/wilayah/search?q=cibinong
     */
    public function search(Request $request)
    {
        $request->validate(['q' => 'required|string|min:3']);

        $wilayah = Wilayah::where('nama', 'ILIKE', "%{$request->q}%")
            ->whereIn('tingkat', ['kabupaten', 'kecamatan'])
            ->orderBy('nama')
            ->limit(20)
            ->get();

        return response()->json(['data' => $wilayah]);
    }

    /**
     * Ambil daftar desa/kelurahan di sebuah kecamatan.
     * Data desa TIDAK diseed di awal (terlalu besar), jadi di-fetch on-demand
     * dari API emsifa lalu di-cache ke tabel wilayah saat pertama diakses.
     * GET /api/wilayah/{kode_kecamatan}/desa
     */
    public function desa(string $kodeKecamatan)
    {
        $kecamatan = Wilayah::where('kode', $kodeKecamatan)
            ->where('tingkat', 'kecamatan')
            ->first();

        if (!$kecamatan) {
            return response()->json(['message' => 'Kecamatan tidak ditemukan'], 404);
        }

        // Cek cache dulu
        $cached = Wilayah::where('parent_kode', $kodeKecamatan)
            ->where('tingkat', 'desa')
            ->orderBy('nama')
            ->get();

        if ($cached->isNotEmpty()) {
            return response()->json(['data' => $cached, 'source' => 'cache']);
        }

        // Belum ada cache, fetch dari API emsifa
        try {
            $response = Http::retry(2, 1000)->timeout(15)
                ->get("{$this->baseUrl}/villages/{$kodeKecamatan}.json");

            $villages = $response->json();

            if (!is_array($villages)) {
                return response()->json(['message' => 'Data desa tidak tersedia dari sumber'], 502);
            }

            foreach ($villages as $v) {
                Wilayah::updateOrCreate(['kode' => $v['id']], [
                    'nama' => $v['name'],
                    'tingkat' => 'desa',
                    'parent_kode' => $kodeKecamatan,
                ]);
            }

            $desa = Wilayah::where('parent_kode', $kodeKecamatan)
                ->where('tingkat', 'desa')
                ->orderBy('nama')
                ->get();

            return response()->json(['data' => $desa, 'source' => 'api']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal mengambil data desa: ' . $e->getMessage()], 502);
        }
    }
}