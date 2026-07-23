<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscribeWilayah;
use Illuminate\Http\Request;

class SubscribeWilayahController extends Controller
{
    /**
     * GET /api/subscribe-wilayah
     */
    public function index(Request $request)
    {
        $data = SubscribeWilayah::where('user_id', $request->user()->id)
            ->with('wilayah:kode,nama,tingkat')
            ->get();

        return response()->json([
            'data' => $data,
            'kuota' => $request->user()->kuota_subscribe,
            'terpakai' => $data->count(),
        ]);
    }

    /**
     * POST /api/subscribe-wilayah
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'wilayah_kode' => 'required|exists:wilayah,kode',
        ]);

        $user = $request->user();

        $sudahSubscribe = SubscribeWilayah::where('user_id', $user->id)
            ->where('wilayah_kode', $validated['wilayah_kode'])
            ->exists();

        if ($sudahSubscribe) {
            return response()->json(['message' => 'Kamu sudah subscribe wilayah ini'], 422);
        }

        $jumlahSubscribe = SubscribeWilayah::where('user_id', $user->id)->count();

        if ($jumlahSubscribe >= $user->kuota_subscribe) {
            return response()->json([
                'message' => "Kuota subscribe kamu ({$user->kuota_subscribe}) sudah penuh. Kumpulkan poin untuk menambah kuota.",
            ], 422);
        }

        $subscribe = SubscribeWilayah::create([
            'user_id' => $user->id,
            'wilayah_kode' => $validated['wilayah_kode'],
        ]);

        return response()->json(['message' => 'Berhasil subscribe wilayah', 'data' => $subscribe], 201);
    }

    /**
     * DELETE /api/subscribe-wilayah/{wilayah_kode}
     */
    public function destroy(Request $request, string $wilayahKode)
    {
        $deleted = SubscribeWilayah::where('user_id', $request->user()->id)
            ->where('wilayah_kode', $wilayahKode)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Subscribe tidak ditemukan'], 404);
        }

        return response()->json(['message' => 'Berhasil unsubscribe']);
    }
}