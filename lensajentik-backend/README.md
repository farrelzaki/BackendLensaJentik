# 🦟 LensaJentik — Backend API

Backend REST API untuk platform **LensaJentik** — sistem Web-GIS pemetaan dan mitigasi risiko penyebaran **DBD (Demam Berdarah Dengue)** dan **Malaria**. Dikembangkan untuk kompetisi TIC 9.0 2026 (Universitas Jember).

Platform menggabungkan tiga sumber data: **data cuaca real-time** (Open-Meteo), **data ABJ dari kader kesehatan**, dan **laporan crowdsourcing warga** — disatukan menjadi peta risiko interaktif berkode warna yang dapat diakses publik tanpa login.

Dibangun dengan **Laravel 12** · **PostgreSQL** · **Laravel Sanctum**

---

## 📋 Daftar Isi

- [Teknologi](#-teknologi)
- [Fitur Utama](#-fitur-utama)
- [Arsitektur & Struktur](#-arsitektur--struktur)
- [Database & Model](#-database--model)
- [API Endpoints](#-api-endpoints)
- [Sistem Gamifikasi](#-sistem-gamifikasi)
- [Scheduled Jobs](#-scheduled-jobs)
- [Integrasi Eksternal](#-integrasi-eksternal)
- [Gap & Catatan Implementasi](#-gap--catatan-implementasi)
- [Setup & Instalasi](#-setup--instalasi)

---

## 🛠 Teknologi

| Komponen | Teknologi |
|---|---|
| Framework | Laravel 12 (PHP) |
| Database | PostgreSQL |
| Autentikasi | Laravel Sanctum (token-based) |
| Upload Foto | Cloudinary |
| Data Cuaca | Open-Meteo API (gratis) |
| Data Wilayah | emsifa API (Indonesia) |
| Export | Maatwebsite Excel + DomPDF |
| Email | SMTP (Mailtrap untuk dev) |

---

## ✨ Fitur Utama

- **Autentikasi multi-role** — `warga`, `kader`, `admin_puskesmas`, `admin_dinkes`
- **Skor Risiko DBD & Malaria** — Dihitung otomatis dari data cuaca + ABJ + laporan warga dengan sistem pembobotan
- **Prediksi 7–14 hari ke depan** — Menggunakan forecast cuaca Open-Meteo
- **Peta risiko publik** — Skor & level risiko dapat diakses tanpa login untuk seluruh pengguna
- **Laporan Jentik Warga** — Bisa dikirim anonim, dilengkapi upload foto ke Cloudinary
- **ABJ (Angka Bebas Jentik)** — Input data pemeriksaan berkala oleh kader
- **Sistem Notifikasi** — In-app + email saat risiko naik atau cuaca ekstrem (>30mm/hari)
- **Subscribe Wilayah** — User bisa langganan notifikasi per wilayah (kuota gamifikasi)
- **Export Laporan** — Download data ABJ dalam format Excel & PDF
- **Dashboard Admin** — Statistik, tren risiko, perbandingan antar wilayah
- **Gamifikasi** — Sistem poin untuk mendorong partisipasi warga

---

## 🏗 Arsitektur & Struktur

```
app/
├── Console/Commands/
│   ├── RefreshSkorRisiko.php        # Cron: hitung ulang skor risiko semua wilayah
│   └── ReminderPemeriksaanKader.php # Cron: reminder kader belum input ABJ
├── Exports/
│   └── AbjExport.php               # Export data ABJ ke Excel
├── Http/Controllers/Api/
│   ├── Admin/
│   │   ├── DashboardController.php  # Statistik & perbandingan wilayah
│   │   └── UserManagementController.php
│   ├── AuthController.php
│   ├── AbjLaporanController.php
│   ├── CuacaController.php
│   ├── ExportController.php
│   ├── LaporanWargaController.php
│   ├── NotifikasiController.php
│   ├── SkorRisikoController.php
│   ├── SubscribeWilayahController.php
│   └── WilayahController.php
├── Models/                         # 9 Eloquent model
├── Observers/
│   └── UserObserver.php            # Auto-update kuota_subscribe saat poin berubah
├── Providers/
│   └── AppServiceProvider.php
└── Services/
    ├── CloudinaryService.php        # Upload foto ke Cloudinary
    ├── NotificationService.php      # Kirim notif in-app + email ke subscriber
    ├── RiskScoreService.php         # Kalkulasi skor risiko DBD/Malaria
    └── WeatherService.php           # Fetch & cache data cuaca dari Open-Meteo
```

---

## 🗃 Database & Model

### Hierarki Wilayah
```
Provinsi → Kabupaten → Kecamatan → Desa
```
Data desa di-load **on-demand** dari API emsifa dan di-cache lokal (tidak di-seed penuh karena terlalu besar).

### Tabel & Relasi

| Model | Tabel | Keterangan |
|---|---|---|
| `User` | `users` | Role: `warga / kader / admin_puskesmas / admin_dinkes`. Memiliki poin & kuota subscribe |
| `Wilayah` | `wilayah` | Hierarki self-referencing. PK string (`kode`) |
| `DataCuaca` | `data_cuaca` | Suhu avg, kelembapan avg, curah hujan per wilayah per tanggal. Flag `is_forecast` |
| `SkorRisiko` | `skor_risiko` | Skor 0–100, level `rendah/sedang/tinggi`, flag `is_prediksi`, JSON `faktor_perhitungan` |
| `AbjLaporan` | `abj_laporan` | Data ABJ input kader: jumlah rumah diperiksa, positif jentik, ABJ persen |
| `LaporanWarga` | `laporan_warga` | Laporan foto jentik warga. Status: `belum_ditangani / sedang_diproses / selesai` |
| `VerifikasiLaporan` | `verifikasi_laporan` | Konfirmasi laporan oleh user lain (1x per laporan per user) |
| `SubscribeWilayah` | `subscribe_wilayah` | Langganan notifikasi wilayah (dibatasi kuota) |
| `Notifikasi` | `notifikasi` | Notif in-app. Tipe: `kenaikan_risiko / cuaca_ekstrem / info / reminder` |

### Formula Skor Risiko

**Jika ada data ABJ (confidence: kuat):**
```
Skor = (Skor Cuaca × 40%) + (Skor ABJ × 35%) + (Skor Laporan × 25%)
```

**Jika tidak ada data ABJ (confidence: lemah):**
```
Skor = (Skor Cuaca × 65%) + (Skor Laporan × 35%)
```

| Komponen | Logika |
|---|---|
| Skor Cuaca | Dari suhu (optimal 25–30°C), kelembapan (makin tinggi makin berisiko), curah hujan |
| Skor ABJ | `100 - rata_ABJ_30hari` (ABJ rendah = risiko tinggi) |
| Skor Laporan | `jumlah_laporan_aktif_30hari × 20`, maks 100 |

**Level:** `rendah` (<40) · `sedang` (40–70) · `tinggi` (>70)

---

## 🔌 API Endpoints

Base URL: `/api`

### Auth

| Method | Endpoint | Auth | Keterangan |
|---|---|---|---|
| POST | `/auth/register` | — | Daftar sebagai warga |
| POST | `/auth/login` | — | Login semua role |
| POST | `/auth/logout` | ✅ | Hapus token |
| GET | `/auth/me` | ✅ | Data user + wilayah tugas |

### Wilayah (Publik)

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/wilayah` | List wilayah (filter `?tingkat=&parent_kode=`) |
| GET | `/wilayah/search?q=` | Cari wilayah by nama (min. 3 karakter) |
| GET | `/wilayah/{kode}` | Detail wilayah + breadcrumb parent |
| GET | `/wilayah/{kode}/desa` | Daftar desa di kecamatan (lazy-load + cache) |

### Skor Risiko (Publik)

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/skor-risiko/{kode}?jenis=dbd` | Hitung + kembalikan skor hari ini & prediksi 7–14 hari |
| GET | `/skor-risiko/peta?tingkat=&parent_kode=&jenis=` | Data peta skor semua wilayah (dari cache DB) |

### Cuaca (Publik)

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/cuaca/{kode}` | Data cuaca hari ini + forecast 14 hari (cache-first, refresh jika >6 jam) |

### ABJ — Login Required

| Method | Endpoint | Keterangan |
|---|---|---|
| POST | `/abj` | Input data ABJ baru |
| GET | `/abj?wilayah_kode=` | Riwayat ABJ per wilayah |
| GET | `/abj/saya` | Riwayat ABJ milik sendiri |

### Laporan Warga

| Method | Endpoint | Auth | Keterangan |
|---|---|---|---|
| POST | `/laporan-warga` | Opsional | Kirim laporan + foto (boleh anonim, login dapat +10 poin) |
| GET | `/laporan-warga` | — | List laporan (filter `?wilayah_kode=&status=`) |
| GET | `/laporan-warga/{id}` | — | Detail laporan |
| POST | `/laporan-warga/{id}/verifikasi` | ✅ | Konfirmasi laporan (+5 poin, 1x per laporan) |
| PATCH | `/laporan-warga/{id}/status` | ✅ Kader/Admin | Update status laporan |

### Subscribe Wilayah — Login Required

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/subscribe-wilayah` | Daftar subscribe + info kuota |
| POST | `/subscribe-wilayah` | Subscribe wilayah baru (cek kuota) |
| DELETE | `/subscribe-wilayah/{kode}` | Unsubscribe |

### Notifikasi — Login Required

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/notifikasi` | List notifikasi + jumlah belum dibaca |
| PATCH | `/notifikasi/{id}/baca` | Tandai 1 notifikasi dibaca |
| PATCH | `/notifikasi/baca-semua` | Tandai semua notifikasi dibaca |

### Statistik Wilayah (Publik — Sesuai PROJECT_CONTEXT)

> Per keputusan desain: **tidak ada dashboard eksklusif Dinkes/Peneliti**. Seluruh data statistik diakses **publik tanpa login**. Endpoint berikut dipakai oleh halaman Statistik publik.

| Method | Endpoint | Auth | Keterangan |
|---|---|---|---|
| GET | `/skor-risiko/{kode}?jenis=dbd` | — | Skor risiko & prediksi per wilayah |
| GET | `/skor-risiko/peta?tingkat=&parent_kode=` | — | Data semua wilayah untuk render peta |
| GET | `/admin/dashboard/ringkasan` | ✅ Admin | Tren risiko, ABJ, laporan per wilayah |
| GET | `/admin/dashboard/bandingkan` | ✅ Admin | Perbandingan skor antar wilayah |

> **Catatan:** Endpoint `/admin/dashboard/*` masih memerlukan autentikasi admin di implementasi saat ini, namun secara desain produk data statistik ini seharusnya publik. Perlu refactor atau duplikasi endpoint tanpa middleware untuk halaman Statistik publik.

### Admin — Manajemen User (Role `admin_puskesmas` / `admin_dinkes`)

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/admin/users` | List user (filter `?role=&wilayah_kode=`) |
| POST | `/admin/users` | Buat akun kader/admin baru |
| PATCH | `/admin/users/{id}` | Update data user |
| DELETE | `/admin/users/{id}` | Nonaktifkan akun (soft delete) |
| GET | `/admin/users/{id}/kinerja` | Detail kinerja kader (riwayat ABJ + statistik) |

### Export — Login Kader/Admin

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/export/abj/excel?wilayah_kode=&dari=&sampai=` | Download Excel data ABJ |
| GET | `/export/abj/pdf?wilayah_kode=&dari=&sampai=` | Download PDF data ABJ |

---

## 🎮 Sistem Gamifikasi

Mendorong partisipasi warga melalui sistem poin & kuota subscribe:

| Aksi | Poin |
|---|---|
| Kirim laporan warga (login) | +10 poin |
| Verifikasi laporan orang lain | +5 poin |

Sistem juga mengirim **notifikasi tipe `info`** saat kuota subscribe bertambah (reward gamifikasi), sesuai PROJECT_CONTEXT Bagian 8.

**Kuota Subscribe:** `1 + (total_poin ÷ 50)`, maksimal **5 wilayah**.

Contoh: punya 100 poin → kuota 3 wilayah. Dikelola otomatis oleh `UserObserver`.

---

## 🕒 Scheduled Jobs

Didefinisikan di `routes/console.php`:

| Command | Jadwal | Fungsi |
|---|---|---|
| `skor-risiko:refresh` | Setiap 6 jam | Fetch cuaca terbaru + hitung ulang skor risiko DBD & Malaria untuk semua wilayah aktif |
| `reminder-kader:cek` | Setiap minggu | Kirim notif + email ke kader yang >7 hari belum input data ABJ |

Untuk menjalankan scheduler di lokal:
```bash
php artisan schedule:work
```

---

## 🌐 Integrasi Eksternal

| Layanan | Digunakan Untuk |
|---|---|
| [Open-Meteo](https://open-meteo.com) | Data cuaca historis & forecast 7–14 hari (gratis, tanpa API key) |
| [emsifa API](https://github.com/emsifa/api-wilayah-indonesia) | Data wilayah Indonesia (provinsi → desa), on-demand |
| [Cloudinary](https://cloudinary.com) | Penyimpanan & CDN foto laporan warga |
| SMTP / Mailtrap | Pengiriman email notifikasi (Mailtrap untuk dev/staging) |

---

## ⚠️ Gap & Catatan Implementasi

Berikut daftar fitur yang ada di PROJECT_CONTEXT namun **belum atau tidak sepenuhnya diimplementasikan** di backend saat ini:

| # | Fitur di PROJECT_CONTEXT | Status Backend | Catatan |
|---|---|---|---|
| 1 | **KontenEdukasi** (artikel, panduan, kuis) | ❌ Belum ada | Tidak ada tabel/model `KontenEdukasi`. Halaman Edukasi kemungkinan konten statis di frontend |
| 2 | **Endpoint Statistik publik** | ⚠️ Partial | `/admin/dashboard/*` butuh auth admin. Idealnya ada endpoint publik tanpa middleware untuk halaman Statistik |
| 3 | **Notifikasi tipe `reward`** | ⚠️ Tidak ada | Enum tipe hanya: `kenaikan_risiko`, `cuaca_ekstrem`, `info`, `reminder`. Tipe `reward` belum ada, perlu tambah enum |
| 4 | **Export data mentah admin** | ⚠️ Ada tapi tidak sesuai context | Endpoint `/admin/dashboard/export-mentah` ada di kode, tapi PROJECT_CONTEXT Bagian 2 menegaskan tidak ada fitur export untuk admin Dinkes. Sebaiknya dihapus atau dibatasi |
| 5 | **Twibbon / share bukti kontribusi** | ❌ Belum ada | Tidak ada endpoint/logika untuk fitur share. Kemungkinan sepenuhnya di sisi frontend |
| 6 | **Offline-first / PWA** | ❌ Out of scope backend | Ini kebutuhan frontend (service worker). Backend sudah mendukung dengan JSON API stateless |
| 7 | **Kalkulator risiko personal (kuis)** | ❌ Belum ada | Tidak ada model/endpoint untuk kuis. Bisa dikerjakan statis di frontend |

---

## 🚀 Setup & Instalasi

### Prasyarat
- PHP >= 8.2
- Composer
- PostgreSQL
- Ekstensi PHP: `pdo_pgsql`, `gd`

### Langkah Instalasi

```bash
# 1. Clone & install dependency
git clone <repo-url>
cd lensajentik-backend
composer install

# 2. Salin file environment
cp .env.example .env

# 3. Generate app key
php artisan key:generate

# 4. Konfigurasi .env (database, Cloudinary, mail)
# DB_CONNECTION=pgsql
# DB_DATABASE=lensajentik
# CLOUDINARY_CLOUD_NAME=...
# CLOUDINARY_API_KEY=...
# CLOUDINARY_API_SECRET=...

# 5. Jalankan migrasi & seeder
php artisan migrate --seed

# 6. Jalankan server
php artisan serve
```

### Environment Wajib

```env
DB_CONNECTION=pgsql
DB_DATABASE=lensajentik

CLOUDINARY_CLOUD_NAME=your_cloud_name
CLOUDINARY_API_KEY=your_api_key
CLOUDINARY_API_SECRET=your_api_secret

MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_USERNAME=...
MAIL_PASSWORD=...
```

---

## 📄 Lisensi

Proyek ini dikembangkan untuk keperluan internal / akademik. Tidak untuk distribusi publik.
