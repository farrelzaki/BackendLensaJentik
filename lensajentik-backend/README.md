# 🦟 LensaJentik — Backend REST API

Backend REST API untuk platform **LensaJentik** — sistem Web-GIS pemetaan dan mitigasi risiko penyebaran **DBD (Demam Berdarah Dengue)** dan **Malaria**. Dikembangkan untuk kompetisi **TIC 9.0 2026 (Universitas Jember)**.

Platform menggabungkan tiga sumber data: **data cuaca real-time** (Open-Meteo), **data ABJ dari kader kesehatan**, dan **laporan crowdsourcing warga** — disatukan menjadi peta risiko interaktif berkode warna.

---

## 📋 Daftar Isi

- [Tech Stack](#-tech-stack)
- [Prasyarat](#-prasyarat)
- [Quick Start — Lokal](#-quick-start--lokal)
- [Data Dummy untuk Testing](#-data-dummy-untuk-testing)
- [Akun Demo](#-akun-demo)
- [Struktur Proyek](#-struktur-proyek)
- [Environment Variables](#-environment-variables)
- [API Endpoints](#-api-endpoints)
- [Sistem Gamifikasi](#-sistem-gamifikasi)
- [Formula Skor Risiko](#-formula-skor-risiko)
- [Scheduled Jobs](#-scheduled-jobs)
- [Testing Manual](#-testing-manual)
- [Deployment](#-deployment)
- [Gap & Catatan](#-gap--catatan)

---

## 🛠 Tech Stack

| Komponen | Teknologi |
|---|---|
| Framework | **Laravel 12** (PHP) |
| Database | **PostgreSQL** >= 15 |
| Autentikasi | **Laravel Sanctum** (token-based) |
| Upload Foto | **Cloudinary** |
| Data Cuaca | **Open-Meteo API** (gratis, tanpa API key) |
| Data Wilayah | **emsifa API** (wilayah Indonesia) |
| Export | **Maatwebsite Excel** + **DomPDF** |
| Email | SMTP (Mailtrap untuk dev, Resend untuk production) |
| Testing | PHPUnit |

---

## 📦 Prasyarat

- **PHP** >= 8.2
- **Composer** >= 2.x
- **PostgreSQL** >= 15
- Ekstensi PHP: `pdo_pgsql`, `gd`, `fileinfo`, `bcmath`
- (Opsional) Mailtrap account untuk testing email

---

## 🚀 Quick Start — Lokal

### 1. Clone & Install Dependency

```bash
cd BackendLensaJentik/lensajentik-backend
composer install
```

### 2. Konfigurasi Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`, sesuaikan koneksi database:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lensajentik
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

### 3. Buat Database PostgreSQL

```bash
# Via command line
createdb lensajentik

# Atau via pgAdmin: buat database baru bernama "lensajentik"
```

### 4. Jalankan Migrasi & Seeder

```bash
# Reset database + isi data dummy (direkomendasikan)
php artisan migrate:fresh --seed

# Atau hanya jalankan migrasi tanpa data dummy
php artisan migrate
```

### 5. Jalankan Development Server

```bash
php artisan serve
# → Backend berjalan di http://localhost:8000
```

### 6. Verifikasi

```bash
# Cek API health
curl http://localhost:8000/api/wilayah?tingkat=provinsi

# Cek login dengan akun demo
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"farrelmzaki77@gmail.com","password":"password123"}'
```

### 7. (Opsional) Jalankan Scheduler

```bash
# Di terminal terpisah, untuk menjalankan cron job
php artisan schedule:work
```

---

## 🎲 Data Dummy untuk Testing

Project ini dilengkapi dengan **2 set seeder**:

### Seeder Utama (`php artisan migrate:fresh --seed`)

Menjalankan 3 seeder berurutan:

| # | Seeder | Durasi | Isi |
|---|---|---|---|
| 1 | `WilayahSeeder` | ~2-5 menit | Hierarki wilayah Indonesia dari emsifa API (provinsi → desa) |
| 2 | `KontenEdukasiSeeder` | <1 detik | 8 artikel edukasi (DBD, malaria, pencegahan, kuis) |
| 3 | `BogorDemoDataSeeder` | ~3-8 menit | **Data dummy lengkap** untuk Bogor Raya |

### Isi BogorDemoDataSeeder

| Data | Jumlah | Detail |
|---|---|---|
| **Wilayah** | Provinsi + 2 Kab/Kota + ~40 kecamatan + ~500 desa | Kota Bogor + Kabupaten Bogor |
| **Users** | 7 akun | 1 admin, 3 kader, 3 warga |
| **Data Cuaca** | 44 hari × N kecamatan | 30 hari historis + 14 hari prediksi |
| **Skor Risiko** | DBD per kecamatan | Dihitung dari cuaca + ABJ + laporan (0–100) |
| **ABJ Laporan** | 15 laporan | 5 minggu × 3 kader |
| **Laporan Warga** | 60+ laporan | Tersebar acak di seluruh kecamatan, berbagai status |
| **Notifikasi** | 21-49 notif | Semua tipe: `kenaikan_risiko`, `cuaca_ekstrem`, `info`, `reminder`, `reward` |
| **Subscribe** | ~15 langganan | Admin, kader, dan warga subscribe ke wilayah terkait |

### Menjalankan Seeder Spesifik

```bash
# Reset + seeding ulang
php artisan migrate:fresh --seed

# Seeder spesifik saja
php artisan db:seed --class=BogorDemoDataSeeder
php artisan db:seed --class=KontenEdukasiSeeder
php artisan db:seed --class=WilayahSeeder

# Demo data minimal (legacy — hanya 3 user, wilayah Patrang)
php artisan db:seed --class=DemoDataSeeder
```

### Karakteristik Data Cuaca Dummy

Data cuaca di-generate dengan karakteristik realistis Bogor (kota hujan):
- **Suhu:** 21–33°C (lebih dingin saat musim hujan)
- **Kelembapan:** 65–98%
- **Curah hujan:** 0–50mm/hari (musim kemarau), hingga 50mm/hari (musim hujan)
- **Musim:** Oktober–April (hujan), Mei–September (kemarau)

---

## 🔑 Akun Demo

### Akun Testing yang Tersedia

| Role | Email | Password | Wilayah | Poin |
|---|---|---|---|---|
| **Admin Dinkes** | `budi@example.com` | `password` | — | 10 |
| **Kader** | `siti@example.com` | `password` | — | 0 |
| **Admin Dinkes** | `farrelmzaki77@gmail.com` | `password123` | — | 0 |
| **Kader** | `farjeng77@gmail.com` | `password123` | Kecamatan Patrang (Jember) | 80 |
| **Warga** | `sekunifril@gmail.com` | `password123` | Kecamatan Patrang (Jember) | 20 |

> **Catatan:** Password berbeda per akun — perhatikan email dan password yang sesuai.

---

## 📁 Struktur Proyek

```
lensajentik-backend/
├── app/
│   ├── Console/Commands/
│   │   ├── RefreshSkorRisiko.php          # Cron: hitung ulang skor risiko
│   │   ├── RefreshSkorRisikoCuaca.php     # Cron: refresh + fetch cuaca
│   │   ├── ReminderPemeriksaanKader.php   # Cron: reminder kader
│   │   └── FixWilayahCoordinates.php     # Tool: perbaiki koordinat
│   ├── Exports/
│   │   └── AbjExport.php                  # Export Excel data ABJ
│   ├── Http/Controllers/Api/
│   │   ├── Admin/
│   │   │   ├── DashboardController.php    # Statistik admin
│   │   │   └── UserManagementController.php
│   │   ├── AuthController.php             # Register, login, profile
│   │   ├── AbjLaporanController.php       # CRUD data ABJ
│   │   ├── CuacaController.php            # Data cuaca + forecast
│   │   ├── EdukasiController.php          # Artikel & kuis edukasi
│   │   ├── ExportController.php           # Export Excel & PDF
│   │   ├── GeocodeController.php          # Reverse geocoding
│   │   ├── KaderDashboardController.php   # Dashboard kader
│   │   ├── LaporanWargaController.php     # Laporan + verifikasi
│   │   ├── NotifikasiController.php       # Notifikasi in-app
│   │   ├── SkorRisikoController.php       # Skor DBD & Malaria
│   │   ├── StatistikController.php        # Statistik publik
│   │   ├── SubscribeWilayahController.php # Langganan wilayah
│   │   └── WilayahController.php          # Hierarki wilayah
│   ├── Models/                            # 9 Eloquent Model
│   │   ├── User.php
│   │   ├── Wilayah.php
│   │   ├── DataCuaca.php
│   │   ├── SkorRisiko.php
│   │   ├── AbjLaporan.php
│   │   ├── LaporanWarga.php
│   │   ├── VerifikasiLaporan.php
│   │   ├── SubscribeWilayah.php
│   │   └── Notifikasi.php
│   ├── Observers/
│   │   └── UserObserver.php               # Auto-update kuota subscribe
│   └── Services/
│       ├── CloudinaryService.php           # Upload foto ke Cloudinary
│       ├── NotificationService.php         # Notif in-app + email
│       ├── RiskScoreService.php            # Kalkulasi skor risiko
│       └── WeatherService.php              # Fetch + cache cuaca
├── database/
│   ├── migrations/                         # 20 file migrasi
│   └── seeders/
│       ├── DatabaseSeeder.php              # Seeder utama
│       ├── BogorDemoDataSeeder.php         # ⭐ Data dummy lengkap
│       ├── DemoDataSeeder.php              # Data dummy minimal (legacy)
│       ├── KontenEdukasiSeeder.php         # Artikel edukasi
│       └── WilayahSeeder.php              # Hierarki wilayah Indonesia
├── routes/
│   ├── api.php                             # Semua endpoint API
│   └── console.php                         # Scheduled commands
├── tests/
│   ├── Unit/SkorCuacaCalculatorTest.php    # Unit test kalkulasi skor
│   └── Feature/ExampleTest.php
├── .env.example                            # Template environment
└── README.md                               # ← File ini
```

---

## 🔧 Environment Variables

### Database (Wajib)

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lensajentik
DB_USERNAME=postgres
DB_PASSWORD=
```

### Cloudinary — Upload Foto (Wajib untuk fitur laporan)

```env
CLOUDINARY_CLOUD_NAME=your_cloud_name
CLOUDINARY_API_KEY=your_api_key
CLOUDINARY_API_SECRET=your_api_secret
CLOUDINARY_URL=cloudinary://api_key:api_secret@cloud_name
```

> **Testing tanpa Cloudinary:** `DemoDataSeeder` dan `BogorDemoDataSeeder` menggunakan URL dummy (`res.cloudinary.com/demo/...`), sehingga fitur upload & tampil foto tetap bisa di-test meski tanpa Cloudinary.

### Email (Opsional untuk development)

```env
# Development: gunakan log driver (email ditulis ke storage/logs/laravel.log)
MAIL_MAILER=log

# Atau gunakan Mailtrap (dapatkan credential dari mailtrap.io)
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password

# Production (Railway): gunakan Resend
# MAIL_MAILER=resend
# RESEND_API_KEY=re_xxxxxxxxxxxxxxxx
```

### Lainnya

```env
APP_NAME=LensaJentik
APP_ENV=local            # local | production
APP_DEBUG=true           # false di production
APP_URL=http://localhost

# CORS — biarkan * untuk development lokal
FRONTEND_URL=*

# Session & Cache
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

---

## 🔌 API Endpoints

Base URL: `http://localhost:8000/api`

### Auth

| Method | Endpoint | Auth | Rate Limit | Keterangan |
|---|---|---|---|---|
| `POST` | `/auth/register` | — | 5/menit | Daftar warga baru |
| `POST` | `/auth/login` | — | 10/menit | Login (return token) |
| `POST` | `/auth/logout` | ✅ | — | Hapus token |
| `GET` | `/auth/me` | ✅ | — | Data user + wilayah |
| `PATCH` | `/auth/update-profile` | ✅ | — | Update profil |
| `POST` | `/auth/forgot-password` | — | 5/menit | Kirim link reset |
| `POST` | `/auth/reset-password` | — | 5/menit | Reset password |

### Wilayah (Publik)

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/wilayah` | List wilayah (`?tingkat=&parent_kode=`) |
| `GET` | `/wilayah/search?q=` | Cari wilayah (min 3 karakter) |
| `GET` | `/wilayah/terdekat?lat=&lng=` | Wilayah terdekat dari koordinat |
| `GET` | `/wilayah/{kode}` | Detail wilayah + breadcrumb |
| `GET` | `/wilayah/{kode}/desa` | Daftar desa dalam kecamatan |
| `GET` | `/wilayah/{kode}/boundary` | GeoJSON boundary wilayah |

### Skor Risiko & Cuaca (Publik)

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/skor-risiko/{kode}?jenis=dbd` | Skor hari ini + prediksi 7–14 hari |
| `GET` | `/skor-risiko/peta?tingkat=&parent_kode=&jenis=` | Data semua wilayah (dari cache DB) |
| `GET` | `/cuaca/{kode}` | Data cuaca hari ini + forecast 14 hari |

### Laporan Warga

| Method | Endpoint | Auth | Keterangan |
|---|---|---|---|
| `POST` | `/laporan-warga` | Opsional | Kirim laporan + foto (anonim: tanpa poin) |
| `GET` | `/laporan-warga` | — | List laporan (`?wilayah_kode=&status=`) |
| `GET` | `/laporan-warga/{id}` | — | Detail laporan |
| `POST` | `/laporan-warga/{id}/verifikasi` | ✅ | Verifikasi (+5 poin, 1x per laporan) |
| `PATCH` | `/laporan-warga/{id}/status` | ✅ Kader/Admin | Update status |

### ABJ (Kader)

| Method | Endpoint | Keterangan |
|---|---|---|
| `POST` | `/abj` | Input data ABJ baru |
| `GET` | `/abj?wilayah_kode=` | Riwayat ABJ per wilayah |
| `GET` | `/abj/saya` | Riwayat ABJ milik sendiri |

### Notifikasi (Login)

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/notifikasi` | List + jumlah belum dibaca |
| `PATCH` | `/notifikasi/{id}/baca` | Tandai 1 notifikasi dibaca |
| `PATCH` | `/notifikasi/baca-semua` | Tandai semua dibaca |

### Subscribe Wilayah (Login)

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/subscribe-wilayah` | List + info kuota |
| `POST` | `/subscribe-wilayah` | Subscribe (cek kuota) |
| `DELETE` | `/subscribe-wilayah/{kode}` | Unsubscribe |

### Statistik (Publik)

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/statistik/ringkasan?wilayah_kode=` | Ringkasan risiko, ABJ, laporan |
| `GET` | `/statistik/bandingkan?wilayah_kode=` | Perbandingan antar wilayah |

### Edukasi (Publik)

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/edukasi` | List artikel edukasi |
| `GET` | `/edukasi/{slug}` | Detail artikel |
| `GET` | `/edukasi/kuis/pertanyaan` | Soal kuis kalkulator risiko |
| `POST` | `/edukasi/kuis/hitung` | Hitung skor risiko personal |

### Kader Dashboard (Login Kader)

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/kader/dashboard` | Dashboard kader (statistik + ringkasan) |

### Admin (Admin Dinkes/Puskesmas)

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/admin/users?role=&wilayah_kode=` | List user |
| `POST` | `/admin/users` | Buat akun baru |
| `PATCH` | `/admin/users/{id}` | Update user |
| `DELETE` | `/admin/users/{id}` | Nonaktifkan (soft delete) |
| `GET` | `/admin/users/{id}/kinerja` | Detail kinerja kader |

### Export (Kader/Admin)

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/export/abj/excel?wilayah_kode=&dari=&sampai=` | Download Excel |
| `GET` | `/export/abj/pdf?wilayah_kode=&dari=&sampai=` | Download PDF |

### Geocode

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/geocode/reverse?lat=&lng=` | Reverse geocode |
| `GET` | `/geocode/boundary?kode=` | Boundary GeoJSON |
| `POST` | `/geocode/boundary-batch` | Batch boundary |

---

## 🎮 Sistem Gamifikasi

| Aksi | Poin |
|---|---|
| Kirim laporan warga (login) | **+10 poin** |
| Verifikasi laporan orang lain | **+5 poin** |

**Kuota Subscribe:** `1 + (total_poin ÷ 50)`, maksimal **5 wilayah**.

| Poin | Kuota Subscribe |
|---|---|
| 0 | 1 wilayah |
| 50 | 2 wilayah |
| 100 | 3 wilayah |
| 150 | 4 wilayah |
| 200+ | 5 wilayah (maks) |

> Dikelola otomatis oleh `UserObserver`.

---

## 📊 Formula Skor Risiko

### Confidence Kuat (ada data ABJ)

```
Skor = (Skor Cuaca × 40%) + (Skor ABJ × 35%) + (Skor Laporan × 25%)
```

### Confidence Lemah (tidak ada data ABJ)

```
Skor = (Skor Cuaca × 65%) + (Skor Laporan × 35%)
```

### Komponen

| Komponen | Logika |
|---|---|
| **Skor Cuaca** | Suhu (40%) + Kelembapan (30%) + Curah Hujan (30%). Suhu optimal 25–30°C |
| **Skor ABJ** | `100 - rata_ABJ_30hari` (ABJ rendah = risiko tinggi) |
| **Skor Laporan** | `jumlah_laporan_aktif_30hari × 20`, maks 100 |

**Level Risiko:** `rendah` (<40) · `sedang` (40–70) · `tinggi` (>70)

---

## 🕒 Scheduled Jobs

Didefinisikan di `routes/console.php`:

| Command | Jadwal | Fungsi |
|---|---|---|
| `skor-risiko:refresh` | Setiap 6 jam | Fetch cuaca terbaru + hitung ulang skor DBD & Malaria |
| `reminder-kader:cek` | Setiap minggu | Notifikasi ke kader yang >7 hari belum input ABJ |

```bash
# Jalankan scheduler di lokal
php artisan schedule:work

# Atau jalankan command manual
php artisan skor-risiko:refresh
php artisan reminder-kader:cek
```

---

## 🧪 Testing Manual

### Login & Role

```bash
# 1. Login sebagai admin (password: password123)
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"farrelmzaki77@gmail.com","password":"password123"}'

# Response:
# { "token": "1|abc123...", "user": { "id": 3, "role": "admin_dinkes", ... } }

# 2. Simpan token untuk request berikutnya
TOKEN="1|abc123..."

# 3. Cek data user login
curl http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer $TOKEN"

# 4. Login sebagai kader (password: password123)
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"farjeng77@gmail.com","password":"password123"}'

# 5. Login sebagai warga (password: password123)
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"sekunifril@gmail.com","password":"password123"}'
```

### Testing Wilayah & Peta

```bash
# List semua provinsi
curl http://localhost:8000/api/wilayah?tingkat=provinsi

# List kecamatan di Kota Bogor (kode 3271)
curl http://localhost:8000/api/wilayah?tingkat=kecamatan&parent_kode=3271

# Cari wilayah "Bogor"
curl "http://localhost:8000/api/wilayah/search?q=bogor"

# Data peta risiko DBD
curl "http://localhost:8000/api/skor-risiko/peta?tingkat=kecamatan&parent_kode=3271&jenis=dbd"
```

### Testing Laporan Warga

```bash
# 1. Kirim laporan (tanpa login — anonim)
curl -X POST http://localhost:8000/api/laporan-warga \
  -H "Content-Type: application/json" \
  -d '{
    "wilayah_kode": "3271010",
    "latitude": -6.5950,
    "longitude": 106.7920,
    "deskripsi": "Test: ada genangan air di selokan depan rumah"
  }'

# 2. Kirim laporan (dengan login — dapat poin)
curl -X POST http://localhost:8000/api/laporan-warga \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "wilayah_kode": "3271010",
    "latitude": -6.5950,
    "longitude": 106.7920,
    "deskripsi": "Test: bak mandi penuh jentik di rumah kosong"
  }'

# 3. Lihat daftar laporan
curl http://localhost:8000/api/laporan-warga?wilayah_kode=3271010
```

### Testing ABJ (Kader)

```bash
# 1. Login sebagai kader
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"farjeng77@gmail.com","password":"password123"}' \
  | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

# 2. Input data ABJ
curl -X POST http://localhost:8000/api/abj \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "wilayah_kode": "3271010",
    "tanggal_pemeriksaan": "2026-08-04",
    "jumlah_rumah_diperiksa": 25,
    "jumlah_rumah_positif": 3,
    "abj_persen": 88.0,
    "catatan": "3 rumah positif jentik di bak mandi"
  }'

# 3. Lihat riwayat ABJ sendiri
curl http://localhost:8000/api/abj/saya \
  -H "Authorization: Bearer $TOKEN"
```

### Testing Notifikasi

```bash
# 1. Lihat notifikasi
curl http://localhost:8000/api/notifikasi \
  -H "Authorization: Bearer $TOKEN"

# 2. Tandai 1 notifikasi dibaca
curl -X PATCH http://localhost:8000/api/notifikasi/1/baca \
  -H "Authorization: Bearer $TOKEN"

# 3. Tandai semua dibaca
curl -X PATCH http://localhost:8000/api/notifikasi/baca-semua \
  -H "Authorization: Bearer $TOKEN"
```

### Testing dengan Postman / Insomnia

**Environment variables:**

```json
{
  "base_url": "http://localhost:8000/api",
  "token": ""
}
```

**Collection flow:**

1. `POST /auth/login` → copy token ke environment
2. `GET /wilayah?tingkat=kecamatan&parent_kode=3271` → pilih kode wilayah
3. `GET /skor-risiko/peta` → data peta
4. `POST /laporan-warga` → kirim laporan
5. `GET /notifikasi` → cek notifikasi
6. `POST /abj` → input ABJ (login kader)
7. `GET /export/abj/excel` → download export

### PHPUnit

```bash
# Jalankan semua test
php artisan test

# Jalankan test spesifik
php artisan test --filter=SkorCuacaCalculatorTest
```

---

## 🚢 Deployment

### Railway (Backend)

```bash
# 1. Build & deploy via Railway CLI
railway up

# 2. Set environment variables di dashboard Railway:
#    - DB_* (otomatis dari Railway PostgreSQL plugin)
#    - CLOUDINARY_CLOUD_NAME
#    - CLOUDINARY_API_KEY
#    - CLOUDINARY_API_SECRET
#    - MAIL_MAILER=resend
#    - RESEND_API_KEY=re_xxx
#    - FRONTEND_URL=https://lensajentik.vercel.app

# 3. Jalankan migrasi di server production
railway run php artisan migrate --force
```

---

## ⚠️ Gap & Catatan

| # | Fitur | Status | Catatan |
|---|---|---|---|
| 1 | **KontenEdukasi** (artikel, panduan, kuis) | ✅ Ada | Model, migration, seeder, dan controller tersedia |
| 2 | **Endpoint Statistik publik** | ✅ Ada | `/statistik/ringkasan` dan `/statistik/bandingkan` tanpa middleware auth |
| 3 | **Notifikasi tipe `reward`** | ✅ Ada | Sudah ditambahkan ke enum migration |
| 4 | **Prediksi Risiko** | ✅ Ada | Tabel `prediksi_risiko` dan perhitungan 14 hari ke depan |
| 5 | **GeoJSON boundary** | ✅ Ada | Endpoint `/wilayah/{kode}/boundary` untuk peta |
| 6 | **Edukasi Kuis** | ✅ Ada | Endpoint `/edukasi/kuis/pertanyaan` + `/edukasi/kuis/hitung` |
| 7 | **Role middleware** | ⚠️ Partial | Middleware `role:kader,admin_puskesmas,admin_dinkes` digunakan di beberapa endpoint |
| 8 | **Offline-first / PWA** | ❌ Out of scope | Kebutuhan frontend (service worker) |
| 9 | **Twibbon / share kontribusi** | ❌ Belum ada | Bisa dikerjakan statis di frontend |

---

## 📄 Lisensi

Proyek ini dikembangkan untuk keperluan kompetisi **TIC 9.0 2026 (Universitas Jember)**. Tidak untuk distribusi publik.
