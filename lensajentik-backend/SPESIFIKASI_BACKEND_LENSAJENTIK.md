# Spesifikasi Teknis Backend & System Architecture — LensaJentik
**Technology Innovative Challenge 9.0 — 2026**
*Sistem Peringatan Dini Risiko Demam Berdarah dan Malaria Berbasis Web-GIS Partisipatif*

---

## 1. Ringkasan Arsitektur & Teknologi

LensaJentik dibangun menggunakan arsitektur **Client-Server Single Page Application (SPA)** berorientasi pada pemisahan tanggung jawab (*Separation of Concerns*), performa tinggi, skalabilitas, dan integrasi data spasial-iklim secara *real-time*.

```
┌────────────────────────────────────────────────────────────────────────┐
│               PRESENTATION LAYER (Frontend - Vue.js 3 SPA)             │
│   - Web-GIS Interaktif (Leaflet.js + Nominatim GeoJSON Boundary)       │
│   - Visualisasi Tren & Prediksi (Chart.js / Real-time Bar Chart)       │
│   - Pinia State Management (useMapStore, useKaderStore, dll.)         │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ HTTPS / RESTful API (JSON)
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│             APPLICATION LAYER (Backend - Laravel PHP 8.2+)             │
│   - RESTful API Controllers & Request Validation                       │
│   - Authentication (Laravel Sanctum Bearer Token & Role Middleware)    │
│   - Risk Score Engine (SkorCuacaCalculator & RiskScoreService)         │
│   - Console Commands & Job Scheduling (RefreshSkorRisikoCuaca)         │
└───────┬───────────────────────────┬───────────────────────────┬────────┘
        │                           │                           │
        ▼                           ▼                           ▼
┌──────────────┐          ┌──────────────────┐        ┌──────────────────┐
│ BASIS DATA   │          │ JOB SCHEDULER    │        │ API EKSTERNAL    │
│ Relasional   │          │ Queue Worker /   │        │ Open-Meteo API   │
│ (PostgreSQL/ │          │ Cron Job Task    │        │ (Data Cuaca      │
│ MySQL)       │          │ Scheduler        │        │ Real-time & 14D) │
└──────────────┘          └──────────────────┘        └──────────────────┘
```

### Prinsip Desain Kunci Backend:
1. **Model Autentikasi Fleksibel & Akses Publik**:
   - **Warga Publik (Akses Publik)**: Memantau Peta Risiko, mencari wilayah, membaca edukasi, mengisi kalkulator risiko kuis, serta melaporkan genangan jentik (dapat dilakukan secara anonim maupun terdaftar).
   - **Pengguna Terdaftar (Kader & Admin)**: Menggunakan autentikasi berbasis **Laravel Sanctum (Bearer Token)** dengan *Role-Based Access Control* (`warga`, `kader`, `admin_puskesmas`, `admin_dinkes`).
2. **Satu Sumber Kebenaran Skor Risiko (*Single Source of Truth*)**:
   - Seluruh modul (Beranda, Peta Risiko, Statistik, Dashboard Kader) mengakses skor risiko yang bersumber dari tabel `skor_risiko` dan `prediksi_risiko`.
3. **Engine Kalkulasi Skor Cuaca Bio-Klimatologi & Data Lapangan**:
   - Skor risiko dihitung berdasarkan perpaduan indikator bioklimatologi nyamuk *Aedes aegypti* (suhu optimal 25–30°C, kelembapan >60%, dan akumulasi hujan 7 hari terakhir dari **Open-Meteo API**) serta penalti elevasi (>1.000 mdpl).
   - Jika tersedia data pemeriksaan Angka Bebas Jentik (ABJ) langsung dari Kader Kesehatan dalam 14 hari terakhir, *confidence level* secara otomatis meningkat dari **`lemah` (Estimasi Cuaca)** menjadi **`kuat` (Data Lapangan)**.
4. **Perhitungan Otomatis On-Demand & Terjadwal (Hybrid Execution)**:
   - Perhitungan skor risiko dijalankan secara otomatis melalui antrean background job (`HitungSkorRisikoJob` / `skor-risiko:refresh-cuaca`), serta didukung *on-demand hydration* saat wilayah baru diakses.

---

## 2. Skema Basis Data Relasional

Struktur basis data dirancang secara ternormalisasi (3NF) untuk menjamin integritas data dan efisiensi query.

### 2.1 Tabel `users`
Menyimpan data pengguna terdaftar (Warga Terdaftar, Kader Kesehatan, Admin Puskesmas, Admin Dinkes).

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `id` | BIGINT (PK, Auto Increment) | Unique User ID |
| `nama` | VARCHAR(255) | Nama lengkap pengguna |
| `email` | VARCHAR(255), UNIQUE | Email untuk login |
| `password` | VARCHAR(255) | Password terenkripsi (bcrypt) |
| `role` | ENUM('warga','kader','admin_puskesmas','admin_dinkes') | Hak akses (default: 'warga') |
| `wilayah_kode` | VARCHAR(15), NULLABLE (FK → `wilayah.kode`) | Kode wilayah binaan/domisili |
| `status_verifikasi` | BOOLEAN | Status verifikasi akun kader/admin (default: true) |
| `created_at`, `updated_at` | TIMESTAMP | Timestamp standar Laravel |

### 2.2 Tabel `wilayah`
Menyimpan struktur administratif wilayah di Indonesia (Provinsi, Kabupaten/Kota, Kecamatan, Desa/Kelurahan).

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `kode` | VARCHAR(15) (PK) | Kode standar wilayah (misal: '3174010') |
| `nama` | VARCHAR(255) | Nama wilayah (misal: 'KEMBANGAN') |
| `tingkat` | ENUM('provinsi','kabupaten','kecamatan','desa') | Tingkat hirarki wilayah |
| `parent_kode` | VARCHAR(15), NULLABLE (FK → `wilayah.kode`) | Relasi hirarki ke wilayah atasnya |
| `latitude` | NUMERIC(10,7), NULLABLE | Titik koordinat lintang |
| `longitude` | NUMERIC(10,7), NULLABLE | Titik koordinat bujur |
| `elevasi` | NUMERIC(8,2), NULLABLE | Ketinggian wilayah (meter di atas permukaan laut) |
| `created_at`, `updated_at` | TIMESTAMP | Timestamp pencatatan |

### 2.3 Tabel `data_cuaca`
Log historis dan prakiraan data cuaca per wilayah dari Open-Meteo API.

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `id` | BIGINT (PK, Auto Increment) | |
| `wilayah_kode` | VARCHAR(15) (FK → `wilayah.kode`) | |
| `tanggal` | DATE | Tanggal pencatatan cuaca |
| `suhu_avg` | NUMERIC(5,2), NULLABLE | Suhu rata-rata harian (°C) |
| `kelembapan_avg` | NUMERIC(5,2), NULLABLE | Kelembapan nisbi rata-rata (%) |
| `curah_hujan` | NUMERIC(6,2), NULLABLE | Curah hujan harian (mm) |
| `is_forecast` | BOOLEAN | `false` untuk historis, `true` untuk prakiraan |
| `sumber_api` | VARCHAR(50) | 'open-meteo' |
| `created_at`, `updated_at` | TIMESTAMP | |

### 2.4 Tabel `skor_risiko`
Tabel historis skor risiko DBD/Malaria hari ini per wilayah.

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `id` | BIGINT (PK, Auto Increment) | |
| `wilayah_kode` | VARCHAR(15) (FK → `wilayah.kode`) | |
| `jenis_penyakit` | ENUM('dbd','malaria') | Jenis penyakit tular vektor |
| `tanggal` | DATE | Tanggal perhitungan skor |
| `is_prediksi` | BOOLEAN | Default `false` untuk skor hari ini |
| `skor` | NUMERIC(5,2) | Rentang 0–100 (makin tinggi makin berisiko) |
| `level_risiko` | ENUM('rendah','sedang','tinggi','belum_ada_data') | Kategori level risiko |
| `confidence_level` | ENUM('kuat','lemah') | 'kuat' (ada ABJ kader) / 'lemah' (murni cuaca) |
| `faktor_perhitungan` | JSONB / JSON | Detail komponen skor (f_suhu, f_hujan, f_lembap, ABJ, dll.) |
| `created_at`, `updated_at` | TIMESTAMP | |

### 2.5 Tabel `prediksi_risiko`
Tabel proyeksi tren skor risiko 14 hari ke depan per wilayah.

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `id` | BIGINT (PK, Auto Increment) | |
| `wilayah_kode` | VARCHAR(15) (FK → `wilayah.kode`) | |
| `jenis_penyakit` | ENUM('dbd','malaria') | |
| `tanggal_prediksi` | DATE | Tanggal target prediksi (hari ke-1 s.d. ke-14) |
| `tanggal_perhitungan` | DATE | Tanggal kapan prediksi dihitung |
| `skor` | NUMERIC(5,2) | Skor prediksi (0–100) |
| `level_risiko` | ENUM('rendah','sedang','tinggi','belum_ada_data') | Level risiko prediksi |
| `confidence_level` | ENUM('kuat','lemah') | |
| `faktor_perhitungan` | JSONB / JSON | rincian variabel cuaca prediksi |
| `created_at`, `updated_at` | TIMESTAMP | |

### 2.6 Tabel `abj_laporan`
Data pemeriksaan jentik berkala oleh Kader Kesehatan (Digitalisasi ABJ).

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `id` | BIGINT (PK, Auto Increment) | |
| `user_id` | BIGINT (FK → `users.id`) | ID Kader penginput |
| `wilayah_kode` | VARCHAR(15) (FK → `wilayah.kode`) | Kode wilayah lokasi pemeriksaan |
| `jumlah_rumah_diperiksa` | INTEGER | Total rumah/bangunan yang diperiksa |
| `jumlah_rumah_positif` | INTEGER | Total rumah positif ditemukan jentik |
| `abj_persen` | NUMERIC(5,2) | Rumus: `(1 - (positif / diperiksa)) * 100` |
| `tanggal_pemeriksaan` | DATE | Tanggal pelaksanaan pemeriksaan lapangan |
| `catatan` | TEXT, NULLABLE | Catatan temuan tempat perkembangbiakan |
| `created_at`, `updated_at` | TIMESTAMP | |

### 2.7 Tabel `laporan_warga`
Data *crowdsourcing* pelaporan titik genangan air dan sarang jentik liar oleh warga.

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `id` | BIGINT (PK, Auto Increment) | |
| `user_id` | BIGINT, NULLABLE (FK → `users.id`) | ID User jika terdaftar, `NULL` jika anonim |
| `session_id` | VARCHAR(100), NULLABLE | Identifier sesi anonim client |
| `nama_pelapor` | VARCHAR(255), NULLABLE | Nama pelapor (opsional jika anonim) |
| `foto_path` | VARCHAR(255) | Relative path / URL foto bukti genangan |
| `latitude` | NUMERIC(10,7) | Koordinat lokasi genangan |
| `longitude` | NUMERIC(10,7) | Koordinat lokasi genangan |
| `alamat_text` | TEXT, NULLABLE | Deskripsi alamat / hasil reverse geocoding |
| `wilayah_kode` | VARCHAR(15), NULLABLE (FK → `wilayah.kode`) | Kode wilayah terpetakan |
| `deskripsi` | TEXT | Keterangan kondisi genangan air |
| `status` | ENUM('belum_ditangani','diproses','selesai') | Status verifikasi & intervensi (default: 'belum_ditangani') |
| `is_anonim` | BOOLEAN | Flag pelaporan anonim |
| `created_at`, `updated_at` | TIMESTAMP | |

### 2.8 Tabel `verifikasi_laporan`
Verifikasi dan konfirmasi laporan warga oleh kader atau warga lain.

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `id` | BIGINT (PK, Auto Increment) | |
| `laporan_warga_id` | BIGINT (FK → `laporan_warga.id`) | |
| `user_id` | BIGINT (FK → `users.id`) | ID Pengguna yang melakukan verifikasi |
| `status_verifikasi` | ENUM('valid','tidak_valid') | |
| `catatan` | TEXT, NULLABLE | |
| `created_at`, `updated_at` | TIMESTAMP | |

### 2.9 Tabel `subscribe_wilayah`
Daftar wilayah yang dipantau (di-subscribe) pengguna untuk menerima notifikasi.

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `id` | BIGINT (PK, Auto Increment) | |
| `user_id` | BIGINT (FK → `users.id`) | Pengguna terdaftar |
| `wilayah_kode` | VARCHAR(15) (FK → `wilayah.kode`) | Wilayah yang diikuti |
| `created_at`, `updated_at` | TIMESTAMP | |

### 2.10 Tabel `notifikasi`
Notifikasi peringatan risiko, cuaca ekstrem, dan reminder bagi pengguna & kader.

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `id` | BIGINT (PK, Auto Increment) | |
| `user_id` | BIGINT (FK → `users.id`) | Target penerima notifikasi |
| `judul` | VARCHAR(255) | Judul pesan |
| `pesan` | TEXT | Isi ringkas notifikasi |
| `tipe` | ENUM('kenaikan_risiko','cuaca_ekstrem','reminder_kader','laporan_baru') | Kategori notifikasi |
| `is_dibaca` | BOOLEAN | Status baca (default: `false`) |
| `metadata` | JSONB / JSON, NULLABLE | Data konteks tambahan (wilayah_kode, laporan_id, dll.) |
| `created_at`, `updated_at` | TIMESTAMP | |

### 2.11 Tabel `konten_edukasi`
Artikel edukasi pencegahan DBD/Malaria dan panduan 3M Plus.

| Kolom | Tipe Data | Keterangan |
|---|---|---|
| `id` | BIGINT (PK, Auto Increment) | |
| `judul` | VARCHAR(255) | Judul artikel |
| `slug` | VARCHAR(255), UNIQUE | URL-friendly slug |
| `kategori` | ENUM('dbd','malaria','umum') | Kategori materi |
| `ringkasan` | TEXT | Ringkasan singkat artikel |
| `konten` | TEXT | Isi lengkap artikel (format HTML / Markdown) |
| `gambar_url` | VARCHAR(255), NULLABLE | URL ilustrasi artikel |
| `created_at`, `updated_at` | TIMESTAMP | |

---

## 3. Daftar Endpoint API (RESTful Specs)

### 3.1 Otentikasi & Profil Pengguna (`/api/auth`)

| Method | Endpoint | Proteksi / Role | Deskripsi & Respons |
|---|---|---|---|
| `POST` | `/api/auth/register` | Publik (Throttle: 5/min) | Registrasi akun pengguna (`warga` / `kader`). Return user & Bearer Token. |
| `POST` | `/api/auth/login` | Publik (Throttle: 10/min) | Login pengguna. Return user profile & Bearer Token. |
| `POST` | `/api/auth/forgot-password` | Publik (Throttle: 5/min) | Kirim token reset password via email. |
| `POST` | `/api/auth/reset-password` | Publik (Throttle: 5/min) | Reset password menggunakan token valid. |
| `POST` | `/api/auth/logout` | `auth:sanctum` | Revoke token aktif pengguna. |
| `GET` | `/api/auth/me` | `auth:sanctum` | Mengembalikan data profil pengguna aktif beserta wilayah domisili/binaan. |
| `PATCH` | `/api/auth/update-profile` | `auth:sanctum` | Update nama, email, password, atau wilayah domisili. |

---

### 3.2 Peta & Skor Risiko (`/api/skor-risiko`)

| Method | Endpoint | Proteksi | Deskripsi & Parameter |
|---|---|---|---|
| `GET` | `/api/skor-risiko/peta` | Publik | Query agregasi skor risiko per wilayah untuk heatmap peta.<br/>**Query Params:**<br/>- `tingkat`: `provinsi` \| `kabupaten` \| `kecamatan` \| `desa` (default: `kabupaten`)<br/>- `jenis`: `dbd` \| `malaria` (default: `dbd`)<br/>- `parent_kode`: filter wilayah anak (opsional)<br/>- `level_risiko`: filter `tinggi` \| `sedang` \| `rendah` (opsional) |
| `GET` | `/api/skor-risiko/{kode}` | Publik | Detail skor risiko 1 wilayah.<br/>Memicu *on-demand hydration* data cuaca Open-Meteo & kalkulasi skor.<br/>**Response:** `wilayah`, `skor_hari_ini`, `prediksi` ( array 14 hari forecast), indikator cuaca, dan rekomendasi cepat. |
| `GET` | `/api/cuaca/{kode}` | Publik | Mengembalikan log historis & prakiraan data cuaca 30 hari untuk 1 wilayah dari `data_cuaca`. |

---

### 3.3 Pencarian & Master Wilayah (`/api/wilayah`)

| Method | Endpoint | Proteksi | Deskripsi |
|---|---|---|---|
| `GET` | `/api/wilayah` | Publik | Mengambil daftar wilayah berdasarkan `tingkat` dan `parent_kode`. |
| `GET` | `/api/wilayah/search` | Publik | Auto-complete pencarian wilayah berdasarkan nama.<br/>**Query Param:** `q` (minimal 3 karakter). Return list nama & kode wilayah. |
| `GET` | `/api/wilayah/{kode}` | Publik | Mengambil detail 1 wilayah beserta hirarki induk (*breadcrumb*). |
| `GET` | `/api/wilayah/{kode}/desa` | Publik | Mengambil daftar desa/kelurahan di bawah 1 kecamatan. |
| `GET` | `/api/geocode/reverse` | Publik | Proxy reverse-geocoding dari koordinat `lat` & `lng` ke string alamat ringkas. |

---

### 3.4 Crowdsourcing Laporan Warga (`/api/laporan-warga`)

| Method | Endpoint | Proteksi | Deskripsi |
|---|---|---|---|
| `POST` | `/api/laporan-warga` | Publik (Bisa Anonim) | Submit laporan genangan/jentik baru.<br/>**Payload (multipart/form-data):** `foto`, `latitude`, `longitude`, `deskripsi`, `nama_pelapor` (opsional), `is_anonim` (boolean).<br/>**Validasi:** File image (JPEG/PNG/WEBP, Max 10MB). Auto-resolve `wilayah_kode` dari koordinat. |
| `GET` | `/api/laporan-warga` | Publik | Mengambil daftar laporan warga. Supporting filter: `wilayah_kode`, `status`, `page`. |
| `GET` | `/api/laporan-warga/{id}` | Publik | Detail 1 laporan warga beserta status penanganan & jumlah verifikasi. |
| `POST` | `/api/laporan-warga/{id}/verifikasi` | `auth:sanctum` | Verifikasi validitas laporan oleh pengguna lain/kader. |
| `PATCH` | `/api/laporan-warga/{id}/status` | `auth:sanctum` (`kader`, `admin`) | Mengubah status laporan (`belum_ditangani` → `diproses` → `selesai`). |

---

### 3.5 Digitalisasi Pemeriksaan ABJ Kader (`/api/abj`)

| Method | Endpoint | Proteksi | Deskripsi |
|---|---|---|---|
| `POST` | `/api/abj` | `auth:sanctum` | Input data pemeriksaan jentik oleh Kader.<br/>**Payload:** `wilayah_kode`, `jumlah_rumah_diperiksa`, `jumlah_rumah_positif_jentik`, `tanggal_pemeriksaan`, `catatan`.<br/>System auto-calculates `abj_persen = (1 - positif / diperiksa) * 100` dan memperbarui *confidence level* wilayah menjadi `kuat`. |
| `GET` | `/api/abj` | `auth:sanctum` | Mengambil daftar data ABJ dengan filter wilayah & tanggal. |
| `GET` | `/api/abj/saya` | `auth:sanctum` | Mengambil riwayat pemeriksaan jentik khusus kader yang sedang login. |
| `GET` | `/api/kader/dashboard` | `auth:sanctum` (`kader`) | Overview dashboard kader: ringkasan ABJ wilayah binaan, skor risiko terkini, dan statistik laporan warga sekitar. |

---

### 3.6 Langganan Wilayah & Notifikasi (`/api/subscribe-wilayah` & `/api/notifikasi`)

| Method | Endpoint | Proteksi | Deskripsi |
|---|---|---|---|
| `GET` | `/api/subscribe-wilayah` | `auth:sanctum` | Mengambil daftar wilayah yang di-subscribe pengguna terdaftar. |
| `POST` | `/api/subscribe-wilayah` | `auth:sanctum` | Menambah wilayah pantauan (`wilayah_kode`). |
| `DELETE` | `/api/subscribe-wilayah/{kode}` | `auth:sanctum` | Menghapus wilayah dari daftar pantauan. |
| `GET` | `/api/notifikasi` | `auth:sanctum` | Mengambil daftar notifikasi pengguna.<br/>Query: `status=semua` \| `belum_dibaca`. |
| `PATCH` | `/api/notifikasi/baca-semua` | `auth:sanctum` | Menandai seluruh notifikasi pengguna sebagai sudah dibaca. |
| `PATCH` | `/api/notifikasi/{id}/baca` | `auth:sanctum` | Menandai 1 notifikasi sebagai sudah dibaca. |

---

### 3.7 Statistik & Edukasi (`/api/statistik` & `/api/edukasi`)

| Method | Endpoint | Proteksi | Deskripsi |
|---|---|---|---|
| `GET` | `/api/statistik/ringkasan` | Publik | Mengembalikan statistik nasional kasus & rekapitulasi data. |
| `GET` | `/api/statistik/bandingkan` | Publik | Membandingkan tren skor & ABJ antara dua atau lebih wilayah. |
| `GET` | `/api/edukasi` | Publik | Mengambil daftar artikel edukasi pencegahan DBD/Malaria. |
| `GET` | `/api/edukasi/{slug}` | Publik | Mengambil isi lengkap 1 artikel edukasi berdasarkan slug. |
| `GET` | `/api/edukasi/kuis/pertanyaan` | Publik | Mengambil daftar pertanyaan kuis Kalkulator Risiko Personal. |
| `POST` | `/api/edukasi/kuis/hitung` | Publik | Menghitung kalkulasi skor risiko mandiri pengguna berdasarkan jawaban kuis. |

---

### 3.8 Ekspor Laporan Resmi (`/api/export`)

| Method | Endpoint | Proteksi | Deskripsi |
|---|---|---|---|
| `GET` | `/api/export/abj/excel` | `auth:sanctum` (`kader`,`admin`) | Ekspor rekapitulasi data ABJ ke format spreadsheet `.xlsx`. |
| `GET` | `/api/export/abj/pdf` | `auth:sanctum` (`kader`,`admin`) | Ekspor rekapitulasi laporan resmi ABJ ke format dokumen `.pdf`. |

---

## 4. Job Terjadwal & Perhitungan Background (Console Commands)

Sistem menggunakan **Console Commands & Job Queues** Laravel untuk memproses data cuaca dan kalkulasi risiko tanpa menghambat kecepatan respon API.

### 4.1 Console Command `skor-risiko:refresh-cuaca`
- **File**: `app/Console/Commands/RefreshSkorRisikoCuaca.php`
- **Fungsi**: Menarik data cuaca 30 hari dari Open-Meteo API, menghitung indikator bioklimatologi, serta memperbarui tabel `skor_risiko` dan `prediksi_risiko`.
- **Opsi Command**:
  - `--wilayah=KODE`: Memproses 1 wilayah spesifik.
  - `--jenis=dbd|malaria`: Menentukan jenis penyakit (default: `dbd`).
  - `--sync`: Memproses kalkulasi secara langsung dalam mode *synchronous* tanpa masuk ke antrean queue worker.
  - `--limit=N`: Membatasi jumlah wilayah yang diproses saat pengujian/testing.

### 4.2 Queue Job `HitungSkorRisikoJob`
- **File**: `app/Jobs/HitungSkorRisikoJob.php`
- **Fungsi**: Unit pekerjaan asynchronous untuk 1 wilayah yang meng-geocode koordinat jika belum ada, memanggil `WeatherService::fetchFullRange()`, mengevaluasi `SkorCuacaCalculator`, dan memperbarui record di database.

---

## 5. Penyesuaian Alur Aplikasi Berdasarkan Tampilan Frontend Saat Ini

### 5.1 Alur 1: Pemantauan Peta Risiko Nyamuk (Modul `RiskMapView.vue`)
```
[User Buka /peta-resiko]
        │
        ▼
[Frontend Render Leaflet Map Skala Nasional (Zoom Level 5)]
        │
        ├─► Request GET /api/skor-risiko/peta?tingkat=kabupaten&jenis=dbd
        │   Backend mengembalikan agregasi skor & level_risiko seluruh Kabupaten
        │
[User Gunakan Pencarian Wilayah / Klik Wilayah di Peta]
        │
        ├─► Request GET /api/wilayah/search?q=Kembangan
        │   User memilih "Kecamatan KEMBANGAN" (Kode: 3174010)
        │
        ├─► Request GET /api/skor-risiko/3174010?jenis=dbd
        │   Backend memicu WeatherService -> Open-Meteo API (jika belum fresh)
        │   Mengembalikan skor_hari_ini, kelembapan, suhu, curah hujan, & array 14 hari prediksi
        │
        ├─► Frontend Panggil Nominatim OpenStreetMap API Client-Side
        │   Fetch GeoJSON Polygon boundary & koordinat presisi -> Leaflet flyTo(zoom 14)
        │
        ▼
[Frontend Render Panel "Hasil Pemeriksaan"]
        ├─ Teks Keadaan Wilayah dinamis
        ├─ Kondisi Udara (Data Open-Meteo: Suhu °C, Curah Hujan mm, Kelembapan %)
        ├─ Badge Confidence Level ("✓ Data Lapangan" jika ada ABJ Kader, "📡 Estimasi Cuaca" jika murni cuaca)
        ├─ Circular Gauge Skor Risiko (0–100) dengan kode warna (Merah/Kuning/Hijau/Abu-abu)
        ├─ Bar Chart Prediksi Skor Risiko 14 Hari Ke Depan
        ├─ Kartu Rekomendasi "Tindakan Cepat Pelindung Keluarga"
        └─ Tombol "Ikuti Kabar Wilayah ini" -> POST /api/subscribe-wilayah
```

---

### 5.2 Alur 2: Pelaporan Genangan Air & Jentik oleh Warga (`ReportView.vue`)
```
[User Buka /laporan]
        │
        ▼
[Browser Geolocation API Minta Izin GPS]
        ├─ GET /api/geocode/reverse?lat=...&lng=...
        └─ Mengisi otomatis string alamat pada form lokasi
        │
[User Isi Form Laporan]
        ├─ Unggah Foto Genangan (Validasi Client: Image format, Max 10MB)
        ├─ Isi Deskripsi Temuan Genangan Air / Sarang Nyamuk
        └─ Pilih Toggle "Lapor sebagai Anonim" atau "Dengan Identitas"
        │
[User Klik "Kirim Laporan"]
        │
        ▼
[Frontend Request POST /api/laporan-warga (multipart/form-data)]
        │
        ├─ Backend Validasi Ulang File (MIME & Size) & Simpan Foto ke Storage
        ├─ Auto-Resolve wilayah_kode dari Koordinat GPS
        ├─ Insert Record ke Tabel `laporan_warga` (status: 'belum_ditangani')
        └─ Mengembalikan Response 201 Created
        │
        ▼
[Tindak Lanjut & Verifikasi]
        ├─ Verifikasi Validitas oleh Pengguna Lain / Kader: POST /api/laporan-warga/{id}/verifikasi
        └─ Pembaruan Status Intervensi oleh Kader/Admin: PATCH /api/laporan-warga/{id}/status
```

---

### 5.3 Alur 3: Pendataan & Dashboard Kader Kesehatan (`KaderDashboardView.vue` & ABJ Form)
```
[Kader Login via /login]
        │
        ├─► POST /api/auth/login {email, password}
        └─ Backend Mengembalikan Sanctum Bearer Token & Role ('kader')
        │
[Kader Buka Dashboard Kader]
        │
        ├─► GET /api/kader/dashboard (Header: Authorization Bearer Token)
        └─ Render Overview: Skor Risiko Wilayah Binaan, Rata-rata ABJ %, & Ringkasan Laporan Warga
        │
[Kader Input Examination ABJ]
        │
        ├─► POST /api/abj {wilayah_kode, jumlah_rumah_diperiksa, jumlah_rumah_positif_jentik, tanggal, catatan}
        ├─ Backend Hitung `abj_persen = (1 - positif / diperiksa) * 100`
        ├─ Simpan ke Tabel `abj_laporan`
        └─ Otomatis Memperbarui Confidence Level Wilayah Binaan menjadi `kuat` (Data Lapangan)
        │
[Kader Unduh Rekap Laporan Resmi]
        └─► GET /api/export/abj/pdf atau /excel -> Generate Dokumen Rekap Resmi untuk Puskesmas/Dinkes
```

---

## 6. Ringkasan & Panduan Pengujian Sistem

1. **Uji Perhitungan Skor Risiko & Job**:
   - Jalankan `php artisan skor-risiko:refresh-cuaca --sync --limit=10` untuk menguji pemrosesan data cuaca Open-Meteo dan kalkulasi skor risiko secara *synchronous*.
2. **Uji Integrasi Peta Risiko**:
   - Buka `/peta-resiko` di browser, lakukan pencarian wilayah (misal: *Jakarta Barat*, *Kembangan*, *Bogor*), dan pastikan panel "Hasil Pemeriksaan" menampilkan data skor risiko, indikator cuaca, grafik prediksi 14 hari, serta penanda *confidence level* secara akurat.
3. **Uji Laporan Warga & Kader**:
   - Lakukan submit laporan genangan di `/laporan` dan uji otentikasi login kader serta penginputan data ABJ pada endpoint `/api/abj`.
