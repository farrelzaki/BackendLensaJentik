<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\LaporanWarga;
use App\Models\AbjLaporan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run()
    {
        $password = Hash::make('password123'); // Password seragam untuk semua akun demo
        $wilayahKode = '3509730'; // Kecamatan Patrang (misal)

        // 1. Akun Admin Dinkes
        $admin = User::firstOrCreate(
            ['email' => 'farrelmzaki77@gmail.com'],
            [
                'name' => 'Admin LensaJentik',
                'password' => $password,
                'role' => 'admin_dinkes',
                'is_active' => true,
                'poin' => 0
            ]
        );

        // 2. Akun Kader
        $kader = User::firstOrCreate(
            ['email' => 'farjeng77@gmail.com'],
            [
                'name' => 'Kader Jentik Patrang',
                'password' => $password,
                'role' => 'kader',
                'is_active' => true,
                'wilayah_kode' => $wilayahKode,
                'poin' => 50
            ]
        );

        // 3. Akun Warga
        $warga = User::firstOrCreate(
            ['email' => 'sekunifril@gmail.com'],
            [
                'name' => 'Sekun Ifril (Warga)',
                'password' => $password,
                'role' => 'warga',
                'is_active' => true,
                'wilayah_kode' => $wilayahKode,
                'poin' => 20
            ]
        );

        // --- SEED HISTORI ABJ (KADER) ---
        // Bersihkan dulu kalau sudah ada biar gak dobel saat seeder dijalankan ulang
        AbjLaporan::where('kader_id', $kader->id)->delete();
        
        $jumlahMinggu = 4;
        for ($i = $jumlahMinggu; $i >= 0; $i--) {
            // Generate data ABJ seminggu sekali selama 4 minggu terakhir + minggu ini
            $tanggal = Carbon::now()->subWeeks($i)->startOfWeek()->addDays(rand(0, 4));
            $diperiksa = rand(15, 30);
            $positif = rand(0, 5);
            $abjPersen = (($diperiksa - $positif) / $diperiksa) * 100;

            AbjLaporan::create([
                'kader_id' => $kader->id,
                'wilayah_kode' => $kader->wilayah_kode,
                'tanggal_pemeriksaan' => $tanggal,
                'jumlah_rumah_diperiksa' => $diperiksa,
                'jumlah_rumah_positif_jentik' => $positif,
                'abj_persen' => round($abjPersen, 2),
                'catatan' => $positif > 0 ? 'Ditemukan jentik di beberapa bak mandi' : 'Semua bersih'
            ]);
        }

        // --- SEED HISTORI LAPORAN (WARGA) ---
        LaporanWarga::where('user_id', $warga->id)->delete();

        $statusOptions = ['belum_ditangani', 'sedang_diproses', 'selesai'];
        $fotoDummies = [
            'https://res.cloudinary.com/demo/image/upload/sample.jpg',
            'https://res.cloudinary.com/demo/image/upload/v1612456485/sample.jpg',
        ];

        for ($i = 0; $i < 8; $i++) {
            $tanggal = Carbon::now()->subDays(rand(1, 45));
            LaporanWarga::create([
                'user_id' => $warga->id,
                'wilayah_kode' => $wilayahKode,
                'latitude' => -8.172 + (rand(-100, 100) / 10000), // Random sekitar Patrang/Jember
                'longitude' => 113.7 + (rand(-100, 100) / 10000),
                'foto_path' => $fotoDummies[array_rand($fotoDummies)],
                'deskripsi' => 'Ada genangan air di lahan kosong ' . rand(1, 100),
                'status' => $statusOptions[array_rand($statusOptions)],
                'jumlah_verifikasi' => rand(0, 5),
                'created_at' => $tanggal,
                'updated_at' => $tanggal
            ]);
        }

        echo "Demo data seeding completed!\n";
    }
}
