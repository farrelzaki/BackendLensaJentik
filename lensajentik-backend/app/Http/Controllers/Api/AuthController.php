<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    /**
     * Register warga biasa (self-registration).
     * Role kader/admin HANYA dibuat oleh admin lewat endpoint terpisah, bukan lewat sini.
     */
    public function register(Request $request)
    {
        $request->validate([
            'nama'         => 'required|string|max:255',
            'name'         => 'sometimes|string|max:255', // alias
            'email'        => 'required|string|email|max:255|unique:users',
            'password'     => ['required', 'confirmed', PasswordRule::min(8)],
            'phone'        => 'nullable|string|max:20',
            'wilayah_kode' => 'nullable|exists:wilayah,kode',
        ]);

        $nama = $request->nama ?? $request->name ?? '';

        $user = User::create([
            'nama'         => $nama,
            'email'        => $request->email,
            'password'     => Hash::make($request->password),
            'role'         => 'warga', // hardcode, gak boleh diisi dari input user
            'phone'        => $request->phone,
            'wilayah_kode' => $request->wilayah_kode,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registrasi berhasil',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }

    /**
     * Login untuk semua role (warga, kader, admin).
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Email atau password salah'], 401);
        }

        // Cek apakah akun masih aktif (admin bisa menonaktifkan akun lewat PATCH /admin/users/{id})
        if (!$user->is_active) {
            return response()->json(['message' => 'Akun kamu telah dinonaktifkan. Hubungi administrator untuk informasi lebih lanjut.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Logout — cabut token yang sedang dipakai.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout berhasil']);
    }

    /**
     * Ambil data user yang sedang login.
     */
    public function me(Request $request)
    {
        return response()->json(['data' => $request->user()->load('wilayahTugas')]);
    }

    /**
     * PATCH /api/auth/update-profile
     * Update profil sendiri (nama, phone, password). Email & role TIDAK bisa diubah lewat sini.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'nama'                  => 'sometimes|string|max:255',
            'name'                  => 'sometimes|string|max:255', // alias untuk kompatibilitas frontend
            'phone'                 => 'sometimes|nullable|string|max:20',
            'current_password'      => 'required_with:password|string',
            'password'              => 'sometimes|string|min:8|confirmed',
        ]);

        // Kalau mau ganti password, wajib cocokkan current_password dulu
        if (isset($validated['password'])) {
            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'message' => 'Password saat ini salah.',
                    'errors'  => ['current_password' => ['Password saat ini yang kamu masukkan tidak cocok.']],
                ], 422);
            }
        }

        // Ambil hanya field yang boleh diupdate (email & role tidak boleh diubah lewat sini)
        $nama = $validated['nama'] ?? $validated['name'] ?? null;
        $updateData = array_filter([
            'nama'  => $nama,
            'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : null,
        ], fn($v) => $v !== null);

        // Jika phone dikirim secara eksplisit (boleh null/kosong)
        if (array_key_exists('phone', $validated)) {
            $updateData['phone'] = $validated['phone'];
        }

        if (isset($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        if (!empty($updateData)) {
            $user->update($updateData);
        }

        return response()->json([
            'message' => 'Profil berhasil diperbarui',
            'data'    => $user->fresh()->makeHidden(['password', 'remember_token']),
        ]);
    }

    /**
     * POST /api/auth/forgot-password
     * Minta token reset password. Pesan response SELALU generik untuk mencegah enumerasi email.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // Password::sendResetLink() mengirim email reset link via broker bawaan Laravel.
        // Ini menggunakan tabel password_reset_tokens yang sudah ada di migration default.
        $status = Password::sendResetLink(
            $request->only('email'),
            function (User $user, string $token) {
                // Kirim email pakai template kustom kita (bukan default Laravel)
                Mail::send('emails.reset-password', [
                    'nama'  => $user->nama,
                    'token' => $token,
                ], function ($mail) use ($user) {
                    $mail->to($user->email)->subject('Reset Password LensaJentik');
                });
            }
        );

        // Selalu kembalikan pesan generik — jangan bocorkan apakah email terdaftar atau tidak
        return response()->json([
            'message' => 'Jika email kamu terdaftar, link reset password telah dikirim. Periksa inbox atau folder spam.',
        ]);
    }

    /**
     * POST /api/auth/reset-password
     * Reset password menggunakan token yang dikirim via email.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                // Cabut semua token lama supaya sesi lama tidak bisa dipakai lagi
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password berhasil direset. Silakan login dengan password baru.']);
        }

        // Token tidak valid atau sudah expired
        return response()->json([
            'message' => 'Token reset password tidak valid atau sudah kedaluwarsa.',
            'errors'  => ['token' => [__($status)]],
        ], 422);
    }
}