<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LaporanWarga;
use App\Models\VerifikasiLaporan;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LaporanWargaController extends Controller
{
    public function __construct(protected CloudinaryService $cloudinaryService) {}

    /**
     * POST /api/laporan-warga
     * Bisa anonim (tanpa login) atau login. Wajib upload foto.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'wilayah_kode' => 'required|exists:wilayah,kode',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'foto' => 'required|image|mimes:jpeg,jpg,png|max:5120',
            'deskripsi' => 'nullable|string|max:500',
        ]);

        $fotoUrl = $this->cloudinaryService->upload($request->file('foto'), 'laporan-warga');

        $user = Auth::guard('sanctum')->user(); // ambil object user (bukan cuma id), null kalau anonim

        $laporan = LaporanWarga::create([
            'user_id' => $user?->id,
            'wilayah_kode' => $validated['wilayah_kode'],
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'foto_path' => $fotoUrl,
            'deskripsi' => $validated['deskripsi'] ?? null,
            'status' => 'belum_ditangani',
        ]);

        // Kasih poin kalau user login (gamifikasi)
        if ($user) {
            $user->increment('poin', 10);
        }

        return response()->json(['message' => 'Laporan berhasil dikirim', 'data' => $laporan], 201);
    }

    /**
     * GET /api/laporan-warga?wilayah_kode=xxx&status=xxx
     */
    public function index(Request $request)
    {
        $query = LaporanWarga::with('user:id,name')->latest();

        if ($request->filled('wilayah_kode')) {
            $query->where('wilayah_kode', $request->wilayah_kode);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate(20));
    }

    /**
     * GET /api/laporan-warga/{id}
     */
    public function show(string $id)
    {
        $laporan = LaporanWarga::with(['user:id,name', 'verifikasi.user:id,name'])->find($id);

        if (!$laporan) {
            return response()->json(['message' => 'Laporan tidak ditemukan'], 404);
        }

        return response()->json(['data' => $laporan]);
    }

    /**
     * PATCH /api/laporan-warga/{id}/status
     * Hanya admin/kader yang boleh ubah status (ditegakkan lewat middleware role di route).
     */
    public function updateStatus(Request $request, string $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:belum_ditangani,sedang_diproses,selesai',
        ]);

        $laporan = LaporanWarga::find($id);

        if (!$laporan) {
            return response()->json(['message' => 'Laporan tidak ditemukan'], 404);
        }

        $laporan->update($validated);

        return response()->json(['message' => 'Status laporan diperbarui', 'data' => $laporan]);
    }

    /**
     * POST /api/laporan-warga/{id}/verifikasi
     * User lain konfirmasi laporan (wajib login, 1x per laporan).
     */
    public function verifikasi(Request $request, string $id)
    {
        $laporan = LaporanWarga::find($id);

        if (!$laporan) {
            return response()->json(['message' => 'Laporan tidak ditemukan'], 404);
        }

        $sudahVerifikasi = VerifikasiLaporan::where('laporan_id', $id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if ($sudahVerifikasi) {
            return response()->json(['message' => 'Kamu sudah memverifikasi laporan ini'], 422);
        }

        VerifikasiLaporan::create([
            'laporan_id' => $id,
            'user_id' => $request->user()->id,
        ]);

        $laporan->increment('jumlah_verifikasi');
        $request->user()->increment('poin', 5);

        return response()->json(['message' => 'Verifikasi berhasil', 'jumlah_verifikasi' => $laporan->jumlah_verifikasi]);
    }
}