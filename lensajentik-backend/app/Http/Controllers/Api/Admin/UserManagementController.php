<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends Controller
{
    /**
     * GET /api/admin/users?role=kader&wilayah_kode=xxx
     * List user, bisa difilter role & wilayah tugas.
     */
    public function index(Request $request)
    {
        $query = User::with('wilayahTugas:kode,nama')
            ->withCount(['abjLaporan', 'laporanWarga']);

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('wilayah_kode')) {
            $query->where('wilayah_kode', $request->wilayah_kode);
        }

        return response()->json($query->orderBy('nama')->paginate(20));
    }

    /**
     * POST /api/admin/users
     * Bikin akun kader/admin baru. Ini SATU-SATUNYA cara role selain 'warga' bisa dibuat.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
            'name' => 'sometimes|string|max:255', // alias
            'email' => 'required|email|unique:users',
            'password' => ['required', Password::min(8)],
            'role' => 'required|in:kader,admin_puskesmas,admin_dinkes',
            'wilayah_kode' => 'required_if:role,kader|nullable|exists:wilayah,kode',
            'phone' => 'nullable|string|max:20',
        ]);

        $nama = $validated['nama'] ?? $validated['name'] ?? '';

        $user = User::create([
            'nama'         => $nama,
            'email'        => $validated['email'],
            'password'     => Hash::make($validated['password']),
            'role'         => $validated['role'],
            'wilayah_kode' => $validated['wilayah_kode'] ?? null,
            'phone'        => $validated['phone'] ?? null,
        ]);

        return response()->json(['message' => 'Akun berhasil dibuat', 'data' => $user], 201);
    }

    /**
     * PATCH /api/admin/users/{id}
     * Edit data user (role, wilayah tugas, status aktif).
     */
    public function update(Request $request, string $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        $validated = $request->validate([
            'nama' => 'sometimes|string|max:255',
            'name' => 'sometimes|string|max:255', // alias
            'role' => 'sometimes|in:warga,kader,admin_puskesmas,admin_dinkes',
            'wilayah_kode' => 'sometimes|nullable|exists:wilayah,kode',
            'phone' => 'sometimes|nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        // Map 'name' → 'nama' untuk kompatibilitas
        if (isset($validated['name']) && !isset($validated['nama'])) {
            $validated['nama'] = $validated['name'];
        }
        unset($validated['name']);

        $user->update($validated);

        return response()->json(['message' => 'User berhasil diperbarui', 'data' => $user]);
    }

    /**
     * DELETE /api/admin/users/{id}
     * Nonaktifkan akun (soft — bukan hapus permanen, biar data histori ABJ/laporan tetap utuh).
     */
    public function destroy(string $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        $user->update(['is_active' => false]);

        return response()->json(['message' => 'Akun berhasil dinonaktifkan']);
    }

    /**
     * GET /api/admin/users/{id}/kinerja
     * Detail kinerja 1 kader: riwayat ABJ yang diinput + statistik ringkas.
     */
    public function kinerja(string $id)
    {
        $user = User::with('wilayahTugas:kode,nama')->find($id);

        if (!$user || $user->role !== 'kader') {
            return response()->json(['message' => 'Kader tidak ditemukan'], 404);
        }

        $riwayat = $user->abjLaporan()
            ->orderByDesc('tanggal_pemeriksaan')
            ->limit(10)
            ->get();

        $ringkasan = [
            'total_input_abj' => $user->abjLaporan()->count(),
            'rata_rata_abj' => round($user->abjLaporan()->avg('abj_persen') ?? 0, 2),
            'input_30_hari_terakhir' => $user->abjLaporan()
                ->where('tanggal_pemeriksaan', '>=', now()->subDays(30))
                ->count(),
        ];

        return response()->json([
            'user' => $user,
            'ringkasan' => $ringkasan,
            'riwayat_terakhir' => $riwayat,
        ]);
    }
}