<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WilayahController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CuacaController;
use App\Http\Controllers\Api\SkorRisikoController;
use App\Http\Controllers\Api\AbjLaporanController;
use App\Http\Controllers\Api\LaporanWargaController;
use App\Http\Controllers\Api\Admin\UserManagementController;
use App\Http\Controllers\Api\SubscribeWilayahController;
use App\Http\Controllers\Api\NotifikasiController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\StatistikController;
use App\Http\Controllers\Api\EdukasiController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\KaderDashboardController;

Route::prefix('statistik')->group(function () {
    Route::get('/ringkasan', [StatistikController::class, 'ringkasan']);
    Route::get('/bandingkan', [StatistikController::class, 'bandingkan']);
});

Route::prefix('edukasi')->group(function () {
    Route::get('/kuis/pertanyaan', [EdukasiController::class, 'pertanyaanKuis']);
    Route::post('/kuis/hitung', [EdukasiController::class, 'hitungKuis']);
    Route::get('/', [EdukasiController::class, 'index']);
    Route::get('/{slug}', [EdukasiController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'role:kader,admin_puskesmas,admin_dinkes'])->prefix('export')->group(function () {
    Route::get('/abj/excel', [ExportController::class, 'excel']);
    Route::get('/abj/pdf', [ExportController::class, 'pdf']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('subscribe-wilayah')->group(function () {
        Route::get('/', [SubscribeWilayahController::class, 'index']);
        Route::post('/', [SubscribeWilayahController::class, 'store']);
        Route::delete('/{wilayahKode}', [SubscribeWilayahController::class, 'destroy']);
    });

    Route::prefix('notifikasi')->group(function () {
        Route::get('/', [NotifikasiController::class, 'index']);
        Route::patch('/baca-semua', [NotifikasiController::class, 'tandaiSemuaDibaca']);
        Route::patch('/{id}/baca', [NotifikasiController::class, 'tandaiDibaca']);
    });
});
    
Route::middleware(['auth:sanctum', 'role:admin_puskesmas,admin_dinkes'])->prefix('admin')->group(function () {
    Route::prefix('users')->group(function () {
        Route::get('/', [UserManagementController::class, 'index']);
        Route::post('/', [UserManagementController::class, 'store']);
        Route::get('/{id}/kinerja', [UserManagementController::class, 'kinerja']);
        Route::patch('/{id}', [UserManagementController::class, 'update']);
        Route::delete('/{id}', [UserManagementController::class, 'destroy']);
    });
});

// ABJ — wajib login, role apapun
Route::middleware('auth:sanctum')->prefix('abj')->group(function () {
    Route::post('/', [AbjLaporanController::class, 'store']);
    Route::get('/', [AbjLaporanController::class, 'index']);
    Route::get('/saya', [AbjLaporanController::class, 'riwayatSaya']);
});

// Laporan Warga
Route::prefix('laporan-warga')->group(function () {
    Route::post('/', [LaporanWargaController::class, 'store']); // boleh anonim, gak pakai middleware
    Route::get('/', [LaporanWargaController::class, 'index']);
    Route::get('/{id}', [LaporanWargaController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/{id}/verifikasi', [LaporanWargaController::class, 'verifikasi']);
    });

    Route::middleware(['auth:sanctum', 'role:kader,admin_puskesmas,admin_dinkes'])->group(function () {
        Route::patch('/{id}/status', [LaporanWargaController::class, 'updateStatus']);
    });
});

Route::get('/skor-risiko/peta', [SkorRisikoController::class, 'peta']);
Route::post('/skor-risiko/refresh-kabupaten', [SkorRisikoController::class, 'refreshKabupaten']);
Route::get('/skor-risiko/{kode}', [SkorRisikoController::class, 'show']);

Route::get('/cuaca/{kode}', [CuacaController::class, 'show']);

Route::prefix('auth')->group(function () {
    // Rate limiting: register 5x/menit per IP, login 10x/menit per IP (lebih ketat dari register)
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Password reset — publik (tidak butuh login), rate limit ketat untuk cegah spam/enumerasi
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/update-profile', [AuthController::class, 'updateProfile']);
    });
});

Route::prefix('wilayah')->group(function () {
    Route::get('/', [WilayahController::class, 'index']);
    Route::get('/search', [WilayahController::class, 'search']);
    Route::get('/terdekat', [WilayahController::class, 'terdekat']); // harus sebelum /{kode}
    Route::get('/{kode}/boundary', [GeocodeController::class, 'wilayahBoundary']); // harus sebelum /{kode}
    Route::get('/{kode}', [WilayahController::class, 'show']);
    Route::get('/{kode}/desa', [WilayahController::class, 'desa']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/geocode/boundary', [GeocodeController::class, 'boundary']);
Route::post('/geocode/boundary-batch', [GeocodeController::class, 'boundaryBatch']);
Route::get('/geocode/reverse', [GeocodeController::class, 'reverse']);

Route::middleware(['auth:sanctum', 'role:kader'])->prefix('kader')->group(function () {
    Route::get('/dashboard', [KaderDashboardController::class, 'index']);
});
