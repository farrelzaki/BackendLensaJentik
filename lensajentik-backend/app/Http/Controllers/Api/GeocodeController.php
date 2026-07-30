<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GeocodeController extends Controller
{
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
