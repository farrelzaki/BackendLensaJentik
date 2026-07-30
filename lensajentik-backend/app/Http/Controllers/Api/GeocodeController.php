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
            $nama = explode(',', $q)[0];
            $results[$nama] = $this->fetchNominatimGeojson($q);
            // Tanpa jeda — Nominatim toleransi untuk batch kecil
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
                    'limit' => 3,
                    'polygon_geojson' => 1,
                ]);
            $data = $response->json();
            if (empty($data)) return null;
            $best = collect($data)->first(fn($g) => isset($g['geojson']) && str_contains($g['geojson']['type'] ?? '', 'Polygon'))
                ?? collect($data)->first(fn($g) => isset($g['geojson']));
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
