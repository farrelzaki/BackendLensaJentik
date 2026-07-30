<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Urutan:
     *   1. WilayahSeeder        – hierarki wilayah Indonesia (dari API)
     *   2. KontenEdukasiSeeder  – artikel & panduan edukasi
     *   3. BogorDemoDataSeeder  – data dummy LENGKAP wilayah Bogor
     *
     * Jalankan dengan:
     *   php artisan migrate:fresh --seed
     *   php artisan db:seed
     */
    public function run(): void
    {
        $this->command->info('🌱 Menjalankan semua seeder...');
        $this->command->info('');

        $this->call(WilayahSeeder::class);
        $this->call(KontenEdukasiSeeder::class);
        $this->call(BogorDemoDataSeeder::class);

        $this->command->info('');
        $this->command->info('✅ Semua seeder selesai!');
    }
}
