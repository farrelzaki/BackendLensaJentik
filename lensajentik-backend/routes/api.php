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
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\SubscribeWilayahController;
use App\Http\Controllers\Api\NotifikasiController;
use App\Http\Controllers\Api\ExportController;

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

    Route::prefix('dashboard')->group(function () {
        Route::get('/ringkasan', [DashboardController::class, 'ringkasan']);
        Route::get('/bandingkan', [DashboardController::class, 'bandingkan']);
        Route::get('/export-mentah', [DashboardController::class, 'exportMentah']);
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

Route::get('/skor-risiko/{kode}', [SkorRisikoController::class, 'show']);

Route::get('/cuaca/{kode}', [CuacaController::class, 'show']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::prefix('wilayah')->group(function () {
    Route::get('/', [WilayahController::class, 'index']);
    Route::get('/search', [WilayahController::class, 'search']);
    Route::get('/{kode}', [WilayahController::class, 'show']);
    Route::get('/{kode}/desa', [WilayahController::class, 'desa']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
