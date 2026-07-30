<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GeocodeController extends Controller
{
    /**
     * GET /api/geocode/boundary?q=Kota+Bogor
     * Proxy ke Nominatim untuk ambil GeoJSON boundary wilayah.
     */
    public function boundary(Request $request)
    {
        $request->validate(['q' => 'required|string|min:3']);
        return response()->json(['geojson' => $this->fetchNominatimGeojson($request->q)]);
    }

    /**
     * POST /api/geocode/boundary-batch
     * Body: { "queries": ["Babakan Madang, Bogor, Indonesia", ...] }
     * Return: { "results": { "Babakan Madang": {...geojson}, ... } }
     */
    public function boundaryBatch(Request $request)
    {
        $request->validate(['queries' => 'required|array|min:1|max:50']);
        $results = [];
        foreach ($request->queries as $q) {
            $nama = trim(explode(',', $q)[0]);
            $geojson = $this->fetchNominatimGeojson($q);
            // Simpan dengan key asli DAN lowercase trimmed untuk matching yang fleksibel
            $results[$nama] = $geojson;
            $results[strtolower($nama)] = $geojson;
            // Jeda 300ms agar tidak kena rate-limit Nominatim (policy: max 1 req/sec)
            usleep(300000);
        }
        return response()->json(['results' => $results]);
    }

    protected function fetchNominatimGeojson(string $query): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'LensaJentik/1.0'])
                ->timeout(15)
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 5,
                    'polygon_geojson' => 1,
                    'addressdetails' => 0,
                ]);
            $data = $response->json();
            if (empty($data)) return null;

            // 1. Utamakan boundary administratif dengan polygon (kecamatan/kabupaten)
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

            // 3. Fallback terakhir: data pertama yang ada geojson
            if (!$best) {
                $best = collect($data)->first(fn($g) => isset($g['geojson']));
            }

            return $best['geojson'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * GET /api/geocode/reverse?lat=-8.17&lng=113.7
     * Melakukan reverse geocoding menggunakan Nominatim (OpenStreetMap)
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
                'format' => 'json',
                'lat' => $request->lat,
                'lon' => $request->lng,
                'zoom' => 18,
                'addressdetails' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return response()->json([
                    'success' => true,
                    'address' => $data['display_name'] ?? 'Alamat tidak ditemukan',
                    'raw' => $data['address'] ?? null,
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
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
