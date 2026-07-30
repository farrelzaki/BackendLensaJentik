<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use App\Models\Wilayah;
use App\Models\User;
use App\Models\DataCuaca;
use App\Models\SkorRisiko;
use App\Models\AbjLaporan;
use App\Models\LaporanWarga;
use App\Models\Notifikasi;
use App\Models\SubscribeWilayah;
use Carbon\Carbon;

/**
 * BogorDemoDataSeeder
 *
 * Membuat data dummy LENGKAP untuk SELURUH wilayah Bogor
 * (Kota Bogor + Kabupaten Bogor) agar seluruh fitur backend bisa
 * langsung dicek tanpa harus isi data manual.
 *
 * Cakupan data:
 *   1. Wilayah          – provinsi, kab/kota, kecamatan, desa
 *   2. Users            – admin, kader (3), warga (3)
 *   3. DataCuaca        – 30 hari ke belakang + 14 hari prediksi (per kecamatan)
 *   4. SkorRisiko       – dihitung dengan logika mirip RiskScoreService
 *   5. AbjLaporan       – 5 minggu laporan ABJ per kader
 *   6. LaporanWarga     – 60+ laporan warga tersebar di Bogor
 *   7. Notifikasi       – berbagai tipe notifikasi
 *   8. SubscribeWilayah – langganan wilayah
 *
 * Usage:
 *   php artisan db:seed --class=BogorDemoDataSeeder
 *
 * NOTE: Jalankan setelah php artisan migrate:fresh
 *        WilayahSeeder TIDAK perlu dijalankan dulu – seeder ini
 *        sudah mencakup seeding wilayah Bogor.
 */
class BogorDemoDataSeeder extends Seeder
{
    protected string $baseUrl = 'https://emsifa.github.io/api-wilayah-indonesia/api';

    /** @var array<string, array> — cache wilayah per kode */
    protected array $wilayahCache = [];

    /** @var array — daftar semua kecamatan Bogor */
    protected array $kecamatanBogor = [];

    /** @var array — user IDs */
    protected int $adminId;
    protected array $kaderIds = [];
    protected array $wargaIds = [];

    /* ─── Entry Point ─────────────────────────────────────────────────────── */
    public function run(): void
    {
        $start = microtime(true);

        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════════╗');
        $this->command->info('║     🌿 LENSAJENTIK – BOGOR DEMO DATA SEEDER      ║');
        $this->command->info('╚══════════════════════════════════════════════════╝');
        $this->command->info('');

        // ── 1. Wilayah ──────────────────────────────────────────────
        $this->seedWilayahBogor();

        // ── 2. Users ────────────────────────────────────────────────
        $this->seedUsers();

        // ── 3. Cuaca (30 hari historis + 14 hari forecast) ────────
        $this->seedCuaca();

        // ── 4. ABJ Laporan (per kader, 5 minggu) ──────────────────
        $this->seedAbjLaporan();

        // ── 5. Laporan Warga ───────────────────────────────────────
        $this->seedLaporanWarga();

        // ── 6. Skor Risiko ─────────────────────────────────────────
        $this->seedSkorRisiko();

        // ── 7. Subscribe Wilayah ──────────────────────────────────
        $this->seedSubscribe();

        // ── 8. Notifikasi ──────────────────────────────────────────
        $this->seedNotifikasi();

        $elapsed = round(microtime(true) - $start, 1);
        $this->command->info('');
        $this->command->info("✅ Bogor Demo Data SEEDED dalam {$elapsed}s!");
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('🌐 Akses endpoint publik:');
        $this->command->info('   GET /api/wilayah?tingkat=kecamatan&parent_kode=3271  (Kota Bogor)');
        $this->command->info('   GET /api/wilayah?tingkat=kecamatan&parent_kode=3201  (Kab. Bogor)');
        $this->command->info('   GET /api/skor-risiko/peta?tingkat=kecamatan&parent_kode=3271&jenis=dbd');
        $this->command->info('   GET /api/statistik/ringkasan?wilayah_kode=<kode_kecamatan>');
        $this->command->info('');
        $this->command->info('🔑 Akun demo (password: password123):');
        $this->command->info('   Admin → admin.bogor@lensajentik.id');
        $this->command->info('   Kader → kader.bogor1@lensajentik.id / kader.bogor2@lensajentik.id / kader.bogor3@lensajentik.id');
        $this->command->info('   Warga → warga.bogor1@lensajentik.id / warga.bogor2@lensajentik.id / warga.bogor3@lensajentik.id');
        $this->command->info('');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * 1. WILAYAH – Bogor Raya
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function seedWilayahBogor(): void
    {
        $this->command->info('📌 [1/8] Seeding Wilayah Bogor Raya...');

        // Jawa Barat
        $prov = $this->fetch("{$this->baseUrl}/provinces.json");
        $jabar = collect($prov)->firstWhere('name', 'JAWA BARAT');
        if (!$jabar) {
            $this->command->error('❌ Provinsi JAWA BARAT tidak ditemukan di API!');
            return;
        }

        // Insert provinsi
        Wilayah::updateOrCreate(['kode' => $jabar['id']], [
            'nama'     => $jabar['name'],
            'tingkat'  => 'provinsi',
            'parent_kode' => null,
        ]);
        $this->wilayahCache[$jabar['id']] = ['nama' => $jabar['name'], 'tingkat' => 'provinsi'];
        $this->command->info("   ✓ Provinsi: {$jabar['name']}");

        // Fetch all kabupaten/kota
        $regencies = $this->fetch("{$this->baseUrl}/regencies/{$jabar['id']}.json");

        // Filter: hanya Kota Bogor (3271) dan Kabupaten Bogor (3201)
        $targetRegencies = collect($regencies)->filter(function ($reg) {
            return stripos($reg['name'], 'BOGOR') !== false;
        });

        if ($targetRegencies->isEmpty()) {
            $this->command->error('❌ Tidak menemukan Kota/Kabupaten Bogor di API!');
            return;
        }

        foreach ($targetRegencies as $reg) {
            // Insert kabupaten/kota
            Wilayah::updateOrCreate(['kode' => $reg['id']], [
                'nama'     => $reg['name'],
                'tingkat'  => 'kabupaten',  // API menyebut semua "kabupaten" meskipun kota
                'parent_kode' => $jabar['id'],
            ]);
            $this->wilayahCache[$reg['id']] = ['nama' => $reg['name'], 'tingkat' => 'kabupaten'];

            // Fetch kecamatan
            $districts = $this->fetch("{$this->baseUrl}/districts/{$reg['id']}.json");

            $desaCount = 0;
            foreach ($districts as $dist) {
                // Insert kecamatan
                Wilayah::updateOrCreate(['kode' => $dist['id']], [
                    'nama'     => $dist['name'],
                    'tingkat'  => 'kecamatan',
                    'parent_kode' => $reg['id'],
                ]);
                $this->wilayahCache[$dist['id']] = [
                    'nama'        => $dist['name'],
                    'tingkat'     => 'kecamatan',
                    'parent_kode' => $reg['id'],
                ];
                $this->kecamatanBogor[] = $dist['id'];

                // Fetch desa/kelurahan untuk setiap kecamatan
                $villages = $this->fetch("{$this->baseUrl}/villages/{$dist['id']}.json");
                foreach ($villages as $vil) {
                    Wilayah::updateOrCreate(['kode' => $vil['id']], [
                        'nama'     => $vil['name'],
                        'tingkat'  => 'desa',
                        'parent_kode' => $dist['id'],
                    ]);
                    $this->wilayahCache[$vil['id']] = [
                        'nama'        => $vil['name'],
                        'tingkat'     => 'desa',
                        'parent_kode' => $dist['id'],
                    ];
                }
                $desaCount += count($villages);
            }

            $this->command->info("   ✓ {$reg['name']}: " . count($districts) . " kecamatan, {$desaCount} desa");
        }

        $totalKec = count($this->kecamatanBogor);
        $this->command->info("   ✅ Total: {$totalKec} kecamatan Bogor Raya siap.");
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * 2. USERS – Akun demo
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function seedUsers(): void
    {
        $this->command->info('👤 [2/8] Seeding Users...');

        $pw = Hash::make('password123');
        $kec = $this->kecamatanBogor;
        $kecCount = count($kec);

        // Pastikan ada minimal 3 kecamatan
        if ($kecCount < 3) {
            $this->command->error('❌ Kecamatan tidak cukup untuk seeding user!');
            return;
        }

        // --- Admin Dinkes ---
        $admin = User::updateOrCreate(
            ['email' => 'admin.bogor@lensajentik.id'],
            [
                'name'         => 'Admin Dinkes Bogor',
                'password'     => $pw,
                'role'         => 'admin_dinkes',
                'is_active'    => true,
                'poin'         => 0,
                'kuota_subscribe' => 1,
                'phone'        => '081234567890',
            ]
        );
        $this->adminId = $admin->id;

        // --- 3 Kader ---
        $kaderData = [
            ['email' => 'kader.bogor1@lensajentik.id', 'name' => 'Kader Jentik – ' . ($this->wilayahCache[$kec[0]]['nama'] ?? 'Bogor')],
            ['email' => 'kader.bogor2@lensajentik.id', 'name' => 'Kader Jentik – ' . ($this->wilayahCache[$kec[1]]['nama'] ?? 'Bogor')],
            ['email' => 'kader.bogor3@lensajentik.id', 'name' => 'Kader Jentik – ' . ($this->wilayahCache[$kec[2]]['nama'] ?? 'Bogor')],
        ];
        for ($i = 0; $i < 3; $i++) {
            $kader = User::updateOrCreate(
                ['email' => $kaderData[$i]['email']],
                [
                    'name'             => $kaderData[$i]['name'],
                    'password'         => $pw,
                    'role'             => 'kader',
                    'is_active'        => true,
                    'wilayah_kode'     => $kec[$i],
                    'poin'             => rand(30, 120),
                    'kuota_subscribe'  => rand(1, 3),
                    'phone'            => '0812' . rand(10000000, 99999999),
                ]
            );
            $this->kaderIds[] = $kader->id;
        }

        // --- 3 Warga ---
        $wargaData = [
            ['email' => 'warga.bogor1@lensajentik.id', 'name' => 'Budi Santoso (Warga)'],
            ['email' => 'warga.bogor2@lensajentik.id', 'name' => 'Siti Rahayu (Warga)'],
            ['email' => 'warga.bogor3@lensajentik.id', 'name' => 'Asep Hidayat (Warga)'],
        ];
        foreach ($wargaData as $i => $wd) {
            $warga = User::updateOrCreate(
                ['email' => $wd['email']],
                [
                    'name'             => $wd['name'],
                    'password'         => $pw,
                    'role'             => 'warga',
                    'is_active'        => true,
                    'wilayah_kode'     => $kec[$i],
                    'poin'             => rand(10, 80),
                    'kuota_subscribe'  => 1,
                    'phone'            => '0813' . rand(10000000, 99999999),
                ]
            );
            $this->wargaIds[] = $warga->id;
        }

        $this->command->info('   ✓ 1 Admin Dinkes, 3 Kader, 3 Warga');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * 3. CUACA – 30 hari historis + 14 hari prediksi
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function seedCuaca(): void
    {
        $this->command->info('🌦️ [3/8] Seeding Data Cuaca (30 hari historis + 14 forecast)...');

        $hariIni = Carbon::now()->startOfDay();

        // Hapus data cuaca lama untuk semua kecamatan Bogor
        DataCuaca::whereIn('wilayah_kode', $this->kecamatanBogor)->delete();

        $totalInserted = 0;
        $insertBuffer = [];

        foreach ($this->kecamatanBogor as $kode) {
            // ── 30 hari historis ──
            for ($i = 29; $i >= 0; $i--) {
                $tanggal = (clone $hariIni)->subDays($i);
                $cuaca = $this->generateBogorWeather($tanggal);

                $insertBuffer[] = [
                    'wilayah_kode'  => $kode,
                    'tanggal'       => $tanggal->toDateString(),
                    'suhu_avg'      => $cuaca['suhu'],
                    'kelembapan_avg'=> $cuaca['kelembapan'],
                    'curah_hujan'   => $cuaca['curah_hujan'],
                    'is_forecast'   => false,
                    'sumber_api'    => 'seeder-dummy',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];

                if (count($insertBuffer) >= 500) {
                    DataCuaca::insert($insertBuffer);
                    $totalInserted += count($insertBuffer);
                    $insertBuffer = [];
                }
            }

            // ── 14 hari prediksi ──
            for ($i = 1; $i <= 14; $i++) {
                $tanggal = (clone $hariIni)->addDays($i);
                $cuaca = $this->generateBogorWeather($tanggal, true);

                $insertBuffer[] = [
                    'wilayah_kode'  => $kode,
                    'tanggal'       => $tanggal->toDateString(),
                    'suhu_avg'      => $cuaca['suhu'],
                    'kelembapan_avg'=> $cuaca['kelembapan'],
                    'curah_hujan'   => $cuaca['curah_hujan'],
                    'is_forecast'   => true,
                    'sumber_api'    => 'seeder-dummy',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];

                if (count($insertBuffer) >= 500) {
                    DataCuaca::insert($insertBuffer);
                    $totalInserted += count($insertBuffer);
                    $insertBuffer = [];
                }
            }
        }

        // Flush remaining buffer
        if (count($insertBuffer) > 0) {
            DataCuaca::insert($insertBuffer);
            $totalInserted += count($insertBuffer);
        }

        $this->command->info("   ✅ {$totalInserted} record cuaca (44 hari × " . count($this->kecamatanBogor) . " kecamatan)");
    }

    /**
     * Generate data cuaca realistis untuk Bogor.
     * Bogor = kota hujan, suhu 22–33°C, kelembapan 70–95%, curah hujan tinggi.
     */
    protected function generateBogorWeather(Carbon $tanggal, bool $isForecast = false): array
    {
        // Musim hujan (Oktober–April) vs kemarau (Mei–September)
        $bulan = (int) $tanggal->format('n');
        $musimHujan = ($bulan >= 10 || $bulan <= 4);

        // Suhu: sedikit variasi antara musim
        $baseSuhu = $musimHujan ? 24.5 : 26.5;
        $suhu = round($baseSuhu + rand(-30, 50) / 10, 2);  // 21.5–31.5°C
        $suhu = max(21.0, min(33.0, $suhu));

        // Kelembapan: Bogor selalu lembap
        $baseHumidity = $musimHujan ? 85 : 75;
        $humidity = round($baseHumidity + rand(-80, 100) / 10, 2);
        $humidity = max(65.0, min(98.0, $humidity));

        // Curah hujan: Bogor bisa ekstrem
        if ($musimHujan) {
            // 40% chance hujan lebat, 40% hujan sedang, 20% gerimis/cerah
            $roll = rand(1, 100);
            if ($roll <= 40) {
                $hujan = round(rand(150, 500) / 10, 2);  // 15–50mm (lebat)
            } elseif ($roll <= 80) {
                $hujan = round(rand(30, 140) / 10, 2);   // 3–14mm (sedang)
            } else {
                $hujan = round(rand(0, 25) / 10, 2);     // 0–2.5mm (gerimis)
            }
        } else {
            $roll = rand(1, 100);
            if ($roll <= 15) {
                $hujan = round(rand(100, 350) / 10, 2);  // 10–35mm (hujan kemarau)
            } elseif ($roll <= 35) {
                $hujan = round(rand(20, 90) / 10, 2);    // 2–9mm
            } else {
                $hujan = 0;  // cerah
            }
        }

        // Forecast lebih "halus" variasinya
        if ($isForecast) {
            $suhu = round($suhu + rand(-10, 10) / 10, 2);
            $humidity = round($humidity + rand(-50, 50) / 10, 2);
            $hujan = round(max(0, $hujan + rand(-50, 50) / 10), 2);
        }

        return [
            'suhu'         => $suhu,
            'kelembapan'   => $humidity,
            'curah_hujan'  => $hujan,
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * 4. ABJ LAPORAN – per kader, 5 minggu ke belakang
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function seedAbjLaporan(): void
    {
        $this->command->info('🦟 [4/8] Seeding ABJ Laporan...');

        AbjLaporan::whereIn('kader_id', $this->kaderIds)->delete();

        $totalInserted = 0;

        foreach ($this->kaderIds as $idx => $kaderId) {
            $kader = User::find($kaderId);
            if (!$kader || !$kader->wilayah_kode) continue;

            // 5 minggu ke belakang
            for ($week = 4; $week >= 0; $week--) {
                $tanggal = Carbon::now()->subWeeks($week)->startOfWeek()->addDays(rand(0, 5));

                $diperiksa = rand(20, 40);
                $positif   = rand(0, 8);
                $abjPersen = round((($diperiksa - $positif) / $diperiksa) * 100, 2);

                $catatanOptions = [
                    'Lingkungan bersih, warga aktif 3M',
                    'Ditemukan jentik di beberapa bak mandi',
                    'Genangan air di lahan kosong perlu ditangani',
                    'Warga mulai sadar pentingnya 3M Plus',
                    'Beberapa rumah memiliki talang air tersumbat',
                    'Kondisi membaik dibanding minggu lalu',
                    'Perlu edukasi tambahan untuk RT 03',
                    null,
                ];

                AbjLaporan::create([
                    'kader_id'                   => $kaderId,
                    'wilayah_kode'               => $kader->wilayah_kode,
                    'tanggal_pemeriksaan'        => $tanggal->toDateString(),
                    'jumlah_rumah_diperiksa'     => $diperiksa,
                    'jumlah_rumah_positif_jentik'=> $positif,
                    'abj_persen'                 => $abjPersen,
                    'catatan'                    => $catatanOptions[array_rand($catatanOptions)],
                ]);
                $totalInserted++;
            }
        }

        $this->command->info("   ✅ {$totalInserted} laporan ABJ (5 minggu × 3 kader)");
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * 5. LAPORAN WARGA – 60+ laporan tersebar di Bogor
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function seedLaporanWarga(): void
    {
        $this->command->info('📸 [5/8] Seeding Laporan Warga...');

        // Hapus dulu biar bersih
        LaporanWarga::whereIn('wilayah_kode', $this->kecamatanBogor)->delete();

        $statusOptions  = ['belum_ditangani', 'belum_ditangani', 'sedang_diproses', 'sedang_diproses', 'selesai'];
        $fotoDummies = [
            'https://res.cloudinary.com/demo/image/upload/v1612456485/sample.jpg',
            'https://res.cloudinary.com/demo/image/upload/f_auto,q_auto/woman-holding-bucket',
            'https://res.cloudinary.com/demo/image/upload/f_auto,q_auto/standing-water',
            'https://res.cloudinary.com/demo/image/upload/f_auto,q_auto/drainage-ditch',
            'https://res.cloudinary.com/demo/image/upload/f_auto,q_auto/flower-pot-water',
        ];

        $deskripsiOptions = [
            'Ada genangan air di selokan depan rumah, sudah 3 hari tidak surut.',
            'Bak mandi kosong di rumah kosong RT 04, banyak jentik.',
            'Lahan kosong di belakang masjid tergenang air hujan.',
            'Pot bunga dan ban bekas di halaman rumah warga jadi sarang nyamuk.',
            'Talang air rumah tetangga tersumbat dan menggenang.',
            'Tempat minum burung jarang dikuras, ditemukan jentik.',
            'Got depan warung Bu RT mampet dan penuh jentik.',
            'Genangan di bawah pohon rindang sejak hujan deras kemarin.',
            'Ember bekas di kebun belakang sudah mulai berlumut dan ada jentik.',
            'Kolam ikan yang sudah tidak terawat jadi tempat nyamuk bertelur.',
            'Drainase depan komplek tersumbat sampah, air menggenang.',
            'Bekas galian proyek yang tergenang air hujan.',
            'Tempat penampungan air hujan terbuka di atap rumah warga.',
            'Vas bunga di pemakaman banyak yang tergenang air.',
            'Kulkas bekas di pinggir jalan menampung air hujan.',
        ];

        $totalInserted = 0;
        $hariIni = Carbon::now();

        // Sebar laporan ke SEMUA kecamatan
        foreach ($this->kecamatanBogor as $kode) {
            // 1-3 laporan per kecamatan
            $jumlahLaporan = rand(1, 3);

            for ($i = 0; $i < $jumlahLaporan; $i++) {
                // Koordinat sekitar Bogor: lat -6.30 s/d -6.75, lng 106.60 s/d 107.05
                $lat  = -6.30 + (rand(-4500, 0) / 10000);
                $lng  = 106.60 + (rand(0, 4500) / 10000);
                $hari = rand(1, 45);

                $userPool = array_merge($this->wargaIds, [null]); // bisa anonim
                $userId   = $userPool[array_rand($userPool)];

                LaporanWarga::create([
                    'user_id'            => $userId,
                    'wilayah_kode'       => $kode,
                    'latitude'           => round($lat, 7),
                    'longitude'          => round($lng, 7),
                    'foto_path'          => $fotoDummies[array_rand($fotoDummies)],
                    'deskripsi'          => $deskripsiOptions[array_rand($deskripsiOptions)],
                    'status'             => $statusOptions[array_rand($statusOptions)],
                    'jumlah_verifikasi'  => rand(0, 8),
                    'created_at'         => (clone $hariIni)->subDays($hari),
                    'updated_at'         => (clone $hariIni)->subDays(rand(0, $hari)),
                ]);
                $totalInserted++;
            }
        }

        $this->command->info("   ✅ {$totalInserted} laporan warga di " . count($this->kecamatanBogor) . " kecamatan");
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * 6. SKOR RISIKO – dihitung manual (mirip RiskScoreService)
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function seedSkorRisiko(): void
    {
        $this->command->info('📊 [6/8] Menghitung Skor Risiko...');

        SkorRisiko::whereIn('wilayah_kode', $this->kecamatanBogor)->delete();

        $totalInserted  = 0;
        $insertBuffer   = [];
        $hariIni        = Carbon::now()->startOfDay();
        $batas30Hari    = (clone $hariIni)->subDays(30);

        // Preload ABJ averages
        $abjCache = [];
        $abjData = AbjLaporan::whereIn('wilayah_kode', $this->kecamatanBogor)
            ->where('tanggal_pemeriksaan', '>=', $batas30Hari)
            ->get()
            ->groupBy('wilayah_kode');

        foreach ($abjData as $kode => $reports) {
            $abjCache[$kode] = round($reports->avg('abj_persen'), 2);
        }

        // Preload laporan counts
        $laporanCache = [];
        $laporanData = LaporanWarga::whereIn('wilayah_kode', $this->kecamatanBogor)
            ->where('created_at', '>=', $batas30Hari)
            ->where('status', '!=', 'selesai')
            ->get()
            ->groupBy('wilayah_kode');

        foreach ($this->kecamatanBogor as $kode) {
            $laporanCache[$kode] = isset($laporanData[$kode]) ? $laporanData[$kode]->count() : 0;
        }

        // Fetch semua cuaca untuk semua kecamatan
        $cuacaData = DataCuaca::whereIn('wilayah_kode', $this->kecamatanBogor)
            ->orderBy('tanggal')
            ->get()
            ->groupBy('wilayah_kode');

        foreach ($this->kecamatanBogor as $kode) {
            $cuacaList = $cuacaData[$kode] ?? collect();
            $abjPct    = $abjCache[$kode] ?? null;
            $lapCount  = $laporanCache[$kode] ?? 0;

            $confidence = $abjPct !== null ? 'kuat' : 'lemah';
            $abjScore   = $abjPct !== null ? round(max(0, min(100, 100 - $abjPct)), 2) : null;
            $lapScore   = min(100, $lapCount * 20);

            foreach ($cuacaList as $cuaca) {
                $cuacaScore = $this->hitungSkorCuacaDummy($cuaca);

                if ($confidence === 'kuat' && $abjScore !== null) {
                    $skorAkhir = round(($cuacaScore * 0.40) + ($abjScore * 0.35) + ($lapScore * 0.25), 2);
                } else {
                    $skorAkhir = round(($cuacaScore * 0.65) + ($lapScore * 0.35), 2);
                }

                $skorAkhir = max(0, min(100, $skorAkhir));
                $level = $skorAkhir < 40 ? 'rendah' : ($skorAkhir <= 70 ? 'sedang' : 'tinggi');

                $insertBuffer[] = [
                    'wilayah_kode'       => $kode,
                    'jenis_penyakit'     => 'dbd',
                    'tanggal'            => $cuaca->tanggal,
                    'skor'               => $skorAkhir,
                    'level_risiko'       => $level,
                    'confidence_level'   => $confidence,
                    'is_prediksi'        => $cuaca->is_forecast,
                    'faktor_perhitungan' => json_encode([
                        'skor_cuaca'   => round($cuacaScore, 2),
                        'skor_abj'     => $abjScore,
                        'skor_laporan' => $lapScore,
                        'suhu'         => $cuaca->suhu_avg,
                        'kelembapan'   => $cuaca->kelembapan_avg,
                        'curah_hujan'  => $cuaca->curah_hujan,
                        'abj_persen'   => $abjPct,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($insertBuffer) >= 500) {
                    SkorRisiko::insert($insertBuffer);
                    $totalInserted += count($insertBuffer);
                    $insertBuffer = [];
                }
            }
        }

        // Flush
        if (count($insertBuffer) > 0) {
            SkorRisiko::insert($insertBuffer);
            $totalInserted += count($insertBuffer);
        }

        $this->command->info("   ✅ {$totalInserted} skor risiko (44 tanggal × " . count($this->kecamatanBogor) . " kecamatan)");
    }

    /**
     * Hitung skor cuaca — replika logika RiskScoreService.
     */
    protected function hitungSkorCuacaDummy(DataCuaca $cuaca): float
    {
        $suhu = $cuaca->suhu_avg ?? 27;
        if ($suhu >= 25 && $suhu <= 30) {
            $suhuScore = 100;
        } else {
            $selisih = $suhu < 25 ? (25 - $suhu) : ($suhu - 30);
            $suhuScore = max(0, 100 - ($selisih * 10));
        }

        $kelembapan = $cuaca->kelembapan_avg ?? 60;
        $kelembapanScore = max(0, min(100, ($kelembapan - 40) * (100 / 60)));

        $hujan = $cuaca->curah_hujan ?? 0;
        $hujanScore = max(0, min(100, ($hujan / 20) * 100));

        return ($suhuScore * 0.4) + ($kelembapanScore * 0.3) + ($hujanScore * 0.3);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * 7. SUBSCRIBE WILAYAH
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function seedSubscribe(): void
    {
        $this->command->info('🔔 [7/8] Seeding Subscribe Wilayah...');

        SubscribeWilayah::whereIn('user_id', array_merge($this->wargaIds, $this->kaderIds, [$this->adminId]))->delete();

        $totalInserted = 0;

        // Admin subscribe ke beberapa kecamatan
        foreach (array_slice($this->kecamatanBogor, 0, 5) as $kode) {
            SubscribeWilayah::firstOrCreate([
                'user_id'      => $this->adminId,
                'wilayah_kode' => $kode,
            ]);
            $totalInserted++;
        }

        // Kader subscribe ke wilayah binaan + sekitarnya
        foreach ($this->kaderIds as $idx => $kaderId) {
            $kader = User::find($kaderId);
            if (!$kader) continue;

            // Wilayah binaan sendiri
            if ($kader->wilayah_kode) {
                SubscribeWilayah::firstOrCreate([
                    'user_id'      => $kaderId,
                    'wilayah_kode' => $kader->wilayah_kode,
                ]);
                $totalInserted++;
            }

            // + 2 kecamatan terdekat (berdasarkan indeks)
            $extraIdx1 = ($idx + 1) % count($this->kecamatanBogor);
            $extraIdx2 = ($idx + 2) % count($this->kecamatanBogor);
            foreach ([$extraIdx1, $extraIdx2] as $ei) {
                SubscribeWilayah::firstOrCreate([
                    'user_id'      => $kaderId,
                    'wilayah_kode' => $this->kecamatanBogor[$ei],
                ]);
                $totalInserted++;
            }
        }

        // Warga subscribe ke wilayah masing-masing
        foreach ($this->wargaIds as $idx => $wargaId) {
            $warga = User::find($wargaId);
            if (!$warga || !$warga->wilayah_kode) continue;

            SubscribeWilayah::firstOrCreate([
                'user_id'      => $wargaId,
                'wilayah_kode' => $warga->wilayah_kode,
            ]);
            $totalInserted++;
        }

        $this->command->info("   ✅ {$totalInserted} langganan wilayah");
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * 8. NOTIFIKASI – berbagai tipe
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function seedNotifikasi(): void
    {
        $this->command->info('📬 [8/8] Seeding Notifikasi...');

        Notifikasi::whereIn('user_id', array_merge($this->wargaIds, $this->kaderIds, [$this->adminId]))->delete();

        $totalInserted = 0;

        $notifTemplates = [
            'kenaikan_risiko' => [
                ['judul' => '⚠️ Risiko DBD Naik di {wilayah}', 'pesan' => 'Skor risiko DBD di {wilayah} naik dari level rendah ke sedang. Segera lakukan pemeriksaan 3M di lingkungan Anda.'],
                ['judul' => '🚨 Waspada! Risiko Tinggi di {wilayah}', 'pesan' => 'Skor risiko DBD di {wilayah} mencapai level TINGGI. Harap waspada dan laporkan genangan air.'],
            ],
            'cuaca_ekstrem' => [
                ['judul' => '🌧️ Peringatan Hujan Lebat di {wilayah}', 'pesan' => 'Curah hujan tinggi ({mm}mm) terdeteksi di {wilayah}. Waspada genangan air baru.'],
            ],
            'info' => [
                ['judul' => '📋 Jadwal Pemantauan Rutin', 'pesan' => 'Pemantauan jentik berkala minggu ini di {wilayah}. Pastikan data ABJ Anda sudah tercatat.'],
                ['judul' => '💡 Tips 3M Plus Minggu Ini', 'pesan' => 'Jangan lupa kuras bak mandi minimal seminggu sekali! Nyamuk DBD bertelur di air bersih.'],
            ],
            'reminder' => [
                ['judul' => '⏰ Reminder: Input Data ABJ', 'pesan' => 'Anda belum menginput data ABJ minggu ini untuk {wilayah}. Yuk, lengkapi sekarang.'],
            ],
            'reward' => [
                ['judul' => '🎉 Selamat! Anda Mendapat Poin', 'pesan' => 'Terima kasih atas laporan Anda! 10 poin telah ditambahkan ke akun Anda.'],
                ['judul' => '⭐ Quota Subscribe Bertambah!', 'pesan' => 'Selamat! Anda sekarang bisa subscribe ke lebih banyak wilayah.'],
            ],
        ];

        $allUserIds = array_merge($this->wargaIds, $this->kaderIds, [$this->adminId]);

        foreach ($allUserIds as $userId) {
            $user = User::find($userId);
            if (!$user) continue;

            $wilayahNama = $user->wilayah_kode
                ? ($this->wilayahCache[$user->wilayah_kode]['nama'] ?? 'wilayah Anda')
                : 'wilayah Anda';

            // 3-7 notifikasi per user
            $jumlahNotif = rand(3, 7);
            for ($i = 0; $i < $jumlahNotif; $i++) {
                $tipe = array_rand($notifTemplates);
                $template = $notifTemplates[$tipe][array_rand($notifTemplates[$tipe])];

                $judul = str_replace('{wilayah}', $wilayahNama, $template['judul']);
                $pesan = str_replace(
                    ['{wilayah}', '{mm}'],
                    [$wilayahNama, (string) rand(30, 80)],
                    $template['pesan']
                );

                $hariLalu = rand(0, 30);

                Notifikasi::create([
                    'user_id'      => $userId,
                    'wilayah_kode' => $user->wilayah_kode,
                    'tipe'         => $tipe,
                    'judul'        => $judul,
                    'pesan'        => $pesan,
                    'is_read'      => (bool) rand(0, 1),
                    'created_at'   => Carbon::now()->subDays($hariLalu),
                    'updated_at'   => Carbon::now()->subDays($hariLalu),
                ]);
                $totalInserted++;
            }
        }

        $this->command->info("   ✅ {$totalInserted} notifikasi (" . count($allUserIds) . " user × 3-7 notif)");
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * HELPER: Fetch API
     * ═══════════════════════════════════════════════════════════════════════ */

    protected function fetch(string $url): array
    {
        try {
            $response = Http::retry(3, 2000)->timeout(30)->get($url);
            $data = $response->json();

            if (!is_array($data)) {
                $this->command->warn("  ⚠ Response invalid: {$url}");
                return [];
            }

            return $data;
        } catch (\Exception $e) {
            $this->command->error("  ✗ Gagal fetch {$url}: {$e->getMessage()}");
            return [];
        }
    }
}
