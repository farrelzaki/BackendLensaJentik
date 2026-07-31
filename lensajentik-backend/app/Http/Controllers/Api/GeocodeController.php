<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Proxy & cache GeoJSON boundary dari Nominatim.
 *
 * SEMUA panggilan Nominatim wajib lewat controller ini (tidak langsung dari frontend).
 * Hasil di-cache di kolom `wilayah.geojson` agar request berikutnya instan.
 */
class GeocodeController extends Controller
{
    /**
     * GET /api/wilayah/{kode}/boundary
     *
     * Ambil GeoJSON boundary untuk 1 wilayah berdasarkan kode.
     * Cache-first: kalau sudah ada di database, return langsung (0 detik).
     * Kalau belum, backend yang panggil Nominatim, simpan, lalu return.
     *
     * Response: { "geojson": {...} }
     */
    public function wilayahBoundary(string $kode)
    {
        $wilayah = Wilayah::find($kode);

        if (!$wilayah) {
            return response()->json(['message' => 'Wilayah tidak ditemukan'], 404);
        }

        // Cek cache
        if ($wilayah->geojson) {
            return response()->json([
                'kode'    => $wilayah->kode,
                'nama'    => $wilayah->nama,
                'geojson' => $wilayah->geojson,
                'cached'  => true,
            ]);
        }

        // Bangun query dan cari
        $query = $this->buildNominatimQuery($wilayah);
        $geojson = $this->fetchNominatimGeojson($query);

        if ($geojson) {
            $wilayah->update([
                'geojson'           => $geojson,
                'geojson_fetched_at' => now(),
            ]);
        }

        return response()->json([
            'kode'    => $wilayah->kode,
            'nama'    => $wilayah->nama,
            'geojson' => $geojson,
            'cached'  => false,
        ]);
    }

    /**
     * GET /api/geocode/boundary?q=Kota+Bogor&kode=3201
     *
     * Proxy ke Nominatim untuk ambil GeoJSON — dengan cache via kode.
     * Jika `kode` diberikan, simpan hasil ke wilayah tersebut.
     */
    public function boundary(Request $request)
    {
        $request->validate(['q' => 'required|string|min:3']);

        $kode = $request->query('kode');
        $geojson = null;

        // Cek cache via kode
        if ($kode) {
            $wilayah = Wilayah::find($kode);
            if ($wilayah && $wilayah->geojson) {
                return response()->json([
                    'geojson' => $wilayah->geojson,
                    'cached'  => true,
                ]);
            }
        }

        $geojson = $this->fetchNominatimGeojson($request->q);

        // Simpan ke cache jika ada kode
        if ($kode && $geojson) {
            $wilayah = Wilayah::find($kode);
            if ($wilayah) {
                $wilayah->update([
                    'geojson'            => $geojson,
                    'geojson_fetched_at' => now(),
                ]);
            }
        }

        return response()->json([
            'geojson' => $geojson,
            'cached'  => false,
        ]);
    }

    /**
     * POST /api/geocode/boundary-batch
     *
     * Body: { "queries": ["Babakan Madang, Bogor, Indonesia", ...] }
     *
     * Untuk setiap query, CACHE DULU sebelum panggil Nominatim.
     * Hasil per kecamatan disimpan ke kolom geojson wilayah terkait.
     *
     * Return: { "results": { "Babakan Madang": {...geojson}, ... } }
     */
    public function boundaryBatch(Request $request)
    {
        $request->validate(['queries' => 'required|array|min:1|max:50']);

        $results = [];
        $namaKeKode = $this->mapNamaKeKode($request->queries);

        foreach ($request->queries as $q) {
            $nama = trim(explode(',', $q)[0]);
            $kode = $namaKeKode[$nama] ?? $namaKeKode[strtolower($nama)] ?? null;

            // 1. Cek cache
            if ($kode) {
                $wilayah = Wilayah::find($kode);
                if ($wilayah && $wilayah->geojson) {
                    $results[$nama] = $wilayah->geojson;
                    $results[strtolower($nama)] = $wilayah->geojson;
                    continue; // skip Nominatim call
                }
            }

            // 2. Fetch dari Nominatim
            $geojson = $this->fetchNominatimGeojson($q);

            // 3. Simpan cache
            if ($kode && $geojson) {
                $wilayah = Wilayah::find($kode);
                if ($wilayah) {
                    $wilayah->update([
                        'geojson'            => $geojson,
                        'geojson_fetched_at' => now(),
                    ]);
                }
            }

            $results[$nama] = $geojson;
            $results[strtolower($nama)] = $geojson;

            // Jeda 300ms antar request Nominatim (rate limit: 1 req/sec)
            usleep(300000);
        }

        return response()->json(['results' => $results]);
    }

    /**
     * Bangun query Nominatim dengan konteks hierarki wilayah.
     * Contoh: "Kembangan, Jakarta Barat, DKI Jakarta, Indonesia"
     */
    protected function buildNominatimQuery(Wilayah $wilayah): string
    {
        $parts = [$wilayah->nama];

        $parent = $wilayah->parent;
        if ($parent) {
            $parts[] = $parent->nama;
            $gparent = $parent->parent;
            if ($gparent) {
                $parts[] = $gparent->nama;
            }
        }

        $parts[] = 'Indonesia';
        return implode(', ', $parts);
    }

    /**
     * Map nama kecamatan → kode wilayah dari query batch.
     * Mencocokkan nama depan query dengan nama wilayah di database.
     */
    protected function mapNamaKeKode(array $queries): array
    {
        $namaList = array_map(fn($q) => trim(explode(',', $q)[0]), $queries);
        $wilayahList = Wilayah::whereIn('nama', $namaList)
            ->where('tingkat', 'kecamatan')
            ->select('kode', 'nama')
            ->get();

        $map = [];
        foreach ($wilayahList as $w) {
            $map[$w->nama] = $w->kode;
            $map[strtolower($w->nama)] = $w->kode;
        }
        return $map;
    }

    /**
     * Panggil Nominatim API, return GeoJSON polygon administratif.
     */
    protected function fetchNominatimGeojson(string $query): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'LensaJentik/1.0 (backend proxy)'])
                ->timeout(15)
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q'              => $query,
                    'format'         => 'json',
                    'limit'          => 5,
                    'polygon_geojson' => 1,
                    'addressdetails'  => 0,
                ]);

            $data = $response->json();
            if (empty($data)) return null;

            // 1. Utamakan boundary administratif dengan polygon
            $best = collect($data)->first(
                fn($g) => ($g['class'] ?? '') === 'boundary'
                    && ($g['type'] ?? '') === 'administrative'
                    && isset($g['geojson'])
                    && str_contains($g['geojson']['type'] ?? '', 'Polygon')
            );

            // 2. Fallback: boundary apapun dengan polygon
            if (!$best) {
                $best = collect($data)->first(
                    fn($g) => isset($g['geojson']) && str_contains($g['geojson']['type'] ?? '', 'Polygon')
                );
            }

            // 3. Fallback terakhir
            if (!$best) {
                $best = collect($data)->first(fn($g) => isset($g['geojson']));
            }

            return $best['geojson'] ?? null;
        } catch (\Exception $e) {
            logger()->warning("Nominatim fetch gagal untuk '{$query}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * GET /api/geocode/reverse?lat=-8.17&lng=113.7
     * Reverse geocoding pakai Nominatim.
     */
    public function reverse(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'LensaJentik/1.0',
            ])->get('https://nominatim.openstreetmap.org/reverse', [
                'format'         => 'json',
                'lat'            => $request->lat,
                'lon'            => $request->lng,
                'zoom'           => 18,
                'addressdetails' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return response()->json([
                    'success' => true,
                    'address' => $data['display_name'] ?? 'Alamat tidak ditemukan',
                    'raw'     => $data['address'] ?? null,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal mendapatkan alamat dari layanan Geocode',
            ], 502);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghubungi layanan Geocode',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
