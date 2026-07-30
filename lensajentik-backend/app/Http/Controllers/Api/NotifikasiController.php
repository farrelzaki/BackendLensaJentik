<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notifikasi;
use Illuminate\Http\Request;

class NotifikasiController extends Controller
{
    /**
     * GET /api/notifikasi
     */
    public function index(Request $request)
    {
        $data = Notifikasi::where('user_id', $request->user()->id)
            ->with('wilayah:kode,nama')
            ->latest()
            ->paginate(20);

        $belumDibaca = Notifikasi::where('user_id', $request->user()->id)
            ->where('is_dibaca', false)
            ->count();

        return response()->json(['data' => $data, 'belum_dibaca' => $belumDibaca]);
    }

    /**
     * PATCH /api/notifikasi/{id}/baca
     */
    public function tandaiDibaca(Request $request, string $id)
    {
        $notif = Notifikasi::where('user_id', $request->user()->id)->find($id);

        if (!$notif) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan'], 404);
        }

        $notif->update(['is_dibaca' => true]);

        return response()->json(['message' => 'Notifikasi ditandai dibaca']);
    }

    /**
     * PATCH /api/notifikasi/baca-semua
     */
    public function tandaiSemuaDibaca(Request $request)
    {
        Notifikasi::where('user_id', $request->user()->id)
            ->where('is_dibaca', false)
            ->update(['is_dibaca' => true]);

        return response()->json(['message' => 'Semua notifikasi ditandai dibaca']);
    }
}