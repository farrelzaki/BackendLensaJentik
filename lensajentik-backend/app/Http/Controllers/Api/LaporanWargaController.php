<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LaporanWarga;
use App\Models\VerifikasiLaporan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LaporanWargaController extends Controller
{
    public function __construct() {}

    /**
     * POST /api/laporan-warga
     * Bisa anonim (tanpa login) atau login. Wajib upload foto.
     * wilayah_kode bersifat opsional — jika tidak dikirim, sistem akan
     * mencari wilayah terdekat dari koordinat GPS.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'wilayah_kode'  => 'nullable|exists:wilayah,kode',
            'latitude'      => 'required|numeric|between:-90,90',
            'longitude'     => 'required|numeric|between:-180,180',
            'foto'          => 'required|image|mimes:jpeg,jpg,png|max:5120',
            'deskripsi'     => 'nullable|string|max:500',
            'nama_pelapor'  => 'nullable|string|max:255',
            'is_anonim'     => 'nullable|boolean',
        ]);

        // Auto-resolve wilayah_kode dari koordinat jika tidak diberikan
        $wilayahKode = $validated['wilayah_kode'] ?? null;
        if (!$wilayahKode) {
            $wilayahKode = $this->resolveWilayahDariKoordinat(
                $validated['latitude'],
                $validated['longitude']
            );
        }

        // Simpan foto langsung ke public/uploads/
        $file = $request->file('foto');
        $dir = public_path('uploads/laporan-warga');
        if (!is_dir($dir)) { mkdir($dir, 0755, true); }
        $filename = uniqid() . '_' . time() . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);
        $fotoUrl = url('uploads/laporan-warga/' . $filename);

        $user = Auth::guard('sanctum')->user();

        // Fallback: kalau resolveWilayah gagal, pakai kode default
        $wilayahKode = $wilayahKode ?? '3174010'; // Kembangan sebagai fallback

        try {
            $laporan = LaporanWarga::create([
                'user_id'       => $user?->id,
                'wilayah_kode'  => $wilayahKode,
                'latitude'      => $validated['latitude'],
                'longitude'     => $validated['longitude'],
                'foto_path'     => $fotoUrl,
                'deskripsi'     => $validated['deskripsi'] ?? null,
                'status'        => 'belum_ditangani',
            ]);
        } catch (\Exception $e) {
            // Fallback: coba dengan kolom baru (migration belum jalan)
            try {
                $laporan = LaporanWarga::create([
                    'user_id'       => $user?->id,
                    'wilayah_kode'  => $wilayahKode,
                    'latitude'      => $validated['latitude'],
                    'longitude'     => $validated['longitude'],
                    'foto_path'     => $fotoUrl,
                    'deskripsi'     => $validated['deskripsi'] ?? null,
                    'status'        => 'belum_ditangani',
                    'session_id'    => $user ? null : ($request->session()->getId() ?? null),
                    'nama_pelapor'  => $validated['nama_pelapor'] ?? ($user?->nama),
                    'is_anonim'     => $validated['is_anonim'] ?? (!$user),
                ]);
            } catch (\Exception $e2) {
                logger()->error('Laporan warga gagal: ' . $e2->getMessage());
                return response()->json(['message' => 'Server Error: ' . $e2->getMessage()], 500);
            }
        }

        // Gamifikasi: +10 poin untuk user login
        if ($user) {
            $user->poin += 10;
            $user->save();
        }

        return response()->json([
            'message' => 'Laporan berhasil dikirim',
            'data' => $laporan,
            'poin_didapat' => $user ? 10 : 0,
        ], 201);
    }

    /**
     * Cari wilayah_kode kecamatan terdekat dari koordinat GPS.
     * Mencari kecamatan dengan jarak terdekat (±0.1° ≈ 11 km).
     */
    protected function resolveWilayahDariKoordinat(float $lat, float $lng): ?string
    {
        $kecamatan = \App\Models\Wilayah::where('tingkat', 'kecamatan')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('kode', 'latitude', 'longitude')
            ->get();

        if ($kecamatan->isEmpty()) {
            return null;
        }

        $terdekat = null;
        $jarakMin = PHP_FLOAT_MAX;

        foreach ($kecamatan as $kec) {
            $jarak = sqrt(
                pow($lat - (float) $kec->latitude, 2) +
                pow($lng - (float) $kec->longitude, 2)
            );
            if ($jarak < $jarakMin) {
                $jarakMin = $jarak;
                $terdekat = $kec->kode;
            }
        }

        return $terdekat;
    }

    /**
     * GET /api/laporan-warga?wilayah_kode=xxx&status=xxx
     */
    public function index(Request $request)
    {
        $query = LaporanWarga::with('user:id,nama')->latest();

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
        $laporan = LaporanWarga::with(['user:id,nama', 'verifikasi.user:id,nama'])->find($id);

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
            'status' => 'required|in:belum_ditangani,diproses,selesai',
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

        $validated = $request->validate([
            'status_verifikasi' => 'required|in:valid,tidak_valid',
            'catatan'           => 'nullable|string|max:500',
        ]);

        $sudahVerifikasi = VerifikasiLaporan::where('laporan_warga_id', $id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if ($sudahVerifikasi) {
            return response()->json(['message' => 'Kamu sudah memverifikasi laporan ini'], 422);
        }

        VerifikasiLaporan::create([
            'laporan_warga_id'  => $id,
            'user_id'           => $request->user()->id,
            'status_verifikasi' => $validated['status_verifikasi'],
            'catatan'           => $validated['catatan'] ?? null,
        ]);

        $laporan->increment('jumlah_verifikasi');

        // Pakai save() agar UserObserver terpicu dan kuota_subscribe ikut diupdate
        $verifikator = $request->user();
        $verifikator->poin += 5;
        $verifikator->save();

        return response()->json([
            'message' => 'Verifikasi berhasil',
            'jumlah_verifikasi' => $laporan->jumlah_verifikasi,
        ]);
    }
}