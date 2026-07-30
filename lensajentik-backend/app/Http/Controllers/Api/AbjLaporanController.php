<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AbjLaporan;
use Illuminate\Http\Request;

class AbjLaporanController extends Controller
{
    /**
     * POST /api/abj
     * Input ABJ baru (bisa diakses semua role yang login).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'wilayah_kode' => 'required|exists:wilayah,kode',
            'tanggal_pemeriksaan' => 'required|date|before_or_equal:today',
            'jumlah_rumah_diperiksa' => 'required|integer|min:1',
            'jumlah_rumah_positif' => 'required|integer|min:0|lte:jumlah_rumah_diperiksa',
            'catatan' => 'nullable|string|max:1000',
        ]);

        $abjPersen = (($validated['jumlah_rumah_diperiksa'] - $validated['jumlah_rumah_positif'])
            / $validated['jumlah_rumah_diperiksa']) * 100;

        $abj = AbjLaporan::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'abj_persen' => round($abjPersen, 2),
        ]);

        return response()->json(['message' => 'Data ABJ berhasil disimpan', 'data' => $abj], 201);
    }

    /**
     * GET /api/abj?wilayah_kode=xxx
     * Riwayat input ABJ per wilayah, urut terbaru dulu.
     */
    public function index(Request $request)
    {
        $request->validate(['wilayah_kode' => 'required|exists:wilayah,kode']);

        $data = AbjLaporan::where('wilayah_kode', $request->wilayah_kode)
            ->with('user:id,nama')
            ->orderByDesc('tanggal_pemeriksaan')
            ->paginate(20);

        return response()->json($data);
    }

    /**
     * GET /api/abj/saya
     * Riwayat input ABJ milik user yang sedang login.
     */
    public function riwayatSaya(Request $request)
    {
        $data = AbjLaporan::where('user_id', $request->user()->id)
            ->with(['wilayah:kode,nama', 'user:id,nama'])
            ->orderByDesc('tanggal_pemeriksaan')
            ->paginate(20);

        return response()->json($data);
    }
}