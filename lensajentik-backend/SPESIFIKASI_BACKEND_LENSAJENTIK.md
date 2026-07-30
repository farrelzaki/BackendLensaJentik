# Spesifikasi Teknis Backend — LensaJentik
**Technology Innovative Challenge 9.0 — 2026**
Dokumen ini merupakan acuan detail untuk pengembangan backend (API, database, job scheduler) berdasarkan hasil analisis 10 mockup UI yang sudah dirancang tim UI/UX.

---

## 1. Ringkasan Arsitektur

```
┌─────────────────┐        HTTPS/JSON        ┌──────────────────────┐
│   Frontend SPA   │ ───────────────────────► │   Backend REST API   │
│   (Vue.js +      │ ◄─────────────────────── │   (Laravel + PHP)     │
│   Vue Router)     │                          │                       │
└─────────────────┘                          └──────────┬────────────┘
                                                          │
                                    ┌─────────────────────┼─────────────────────┐
                                    ▼                     ▼                     ▼
                          ┌──────────────┐      ┌──────────────────┐  ┌──────────────┐
                          │  PostgreSQL   │      │  Task Scheduler   │  │  API Cuaca    │
                          │  (Database)   │      │  (Cron Jobs)       │  │  Eksternal    │
                          └──────────────┘      └──────────────────┘  └──────────────┘
```

**Prinsip desain kunci yang wajib dipegang tim backend:**

1. **Dua kelas akses berbeda total secara arsitektur:**
   - **Warga publik** → *stateless, tanpa login*. Identitas diwakili oleh **session token anonim** (UUID) yang di-generate di client (localStorage) dan dikirim di header tiap request (`X-Session-Id`). Backend TIDAK menyimpan data pribadi apa pun untuk warga.
   - **Kader** → autentikasi penuh dengan Laravel Sanctum (bearer token).

2. **Satu sumber kebenaran skor risiko.** Semua modul (Beranda, Peta Resiko, Statistik) membaca dari tabel `skor_risiko` yang sama — tidak boleh ada logika hitung skor terpisah di lebih dari satu tempat.

3. **Job terjadwal adalah jantung sistem.** Skor risiko tidak dihitung real-time saat request datang, melainkan dihitung ulang secara berkala di background lalu di-cache ke tabel. Ini penting untuk performa dan supaya API cuaca eksternal tidak dipanggil berulang-ulang per request pengguna.

---

## 2. Strategi Session Anonim (Wajib Diputuskan Sebelum Coding)

Karena warga tidak login tapi tetap butuh fitur **poin, kuota subscribe, dan notifikasi personal**, seluruh sistem gamifikasi warga diikat ke `session_id` (UUID v4), bukan `user_id`.

**Alur:**
1. Saat pertama kali membuka web, frontend generate UUID → simpan di `localStorage` sebagai `lensajentik_session_id`.
2. Setiap request dari warga (laporan, subscribe, notifikasi) menyertakan header `X-Session-Id: <uuid>`.
3. Backend membuat baris di tabel `sesi_warga` saat UUID baru pertama kali muncul (lazy creation — tidak perlu endpoint register terpisah).
4. Jika localStorage dihapus (ganti device/browser), riwayat poin & subscribe otomatis hilang — ini adalah **trade-off yang disengaja** sesuai prinsip privasi di proposal (tidak ada data pribadi tersimpan).

> ⚠️ **Catatan untuk tim:** ini adalah keputusan arsitektur paling penting di seluruh sistem. Semua tabel yang menyentuh "warga" (bukan kader) menggunakan `session_id` sebagai foreign key, bukan `user_id`.

---

## 3. Skema Database (PostgreSQL)

### 3.1 `wilayah`
Menyimpan batas administratif (kecamatan/desa) dan datanya.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| nama | VARCHAR(150) | mis. "Kecamatan Patrang" |
| kabupaten_kota | VARCHAR(150) | |
| provinsi | VARCHAR(150) | |
| geometri | GEOMETRY(POLYGON, 4326) | perlu ekstensi **PostGIS** |
| created_at, updated_at | TIMESTAMP | |

> 🔧 **Perlu:** aktifkan ekstensi `postgis` di PostgreSQL untuk query spasial (search kecamatan, filter berdasarkan lokasi).

### 3.2 `data_cuaca`
Log historis data cuaca per wilayah, ditarik berkala dari API eksternal.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| wilayah_id | UUID (FK → wilayah) | |
| suhu | DECIMAL(4,1) | celcius |
| kelembapan | DECIMAL(5,2) | persen |
| curah_hujan | DECIMAL(6,2) | mm |
| kondisi | VARCHAR(50) | mis. "Sering Gerimis" (untuk tampilan seperti di mockup Peta Resiko) |
| sumber | VARCHAR(30) | "OpenWeatherMap" / "BMKG" |
| diambil_pada | TIMESTAMP | |

### 3.3 `data_abj`
Input digital kader — pengganti kartu kertas.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| kader_id | UUID (FK → kader) | |
| wilayah_id | UUID (FK → wilayah) | RT/RW binaan |
| jumlah_rumah_diperiksa | INTEGER | |
| jumlah_rumah_positif_jentik | INTEGER | |
| abj_persen | DECIMAL(5,2) | dihitung otomatis: `(1 - positif/diperiksa) * 100` |
| tanggal_pemeriksaan | DATE | |
| catatan | TEXT (nullable) | |
| created_at | TIMESTAMP | |

### 3.4 `laporan_warga`
Crowdsourcing genangan air.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| session_id | UUID (FK → sesi_warga, nullable jika anonim) | |
| nama_pelapor | VARCHAR(100), nullable | kosong jika "Lapor sebagai anonim" |
| foto_url | VARCHAR(255) | path/URL file tersimpan |
| latitude | DECIMAL(10,7) | |
| longitude | DECIMAL(10,7) | |
| alamat_text | VARCHAR(255) | hasil reverse-geocoding, sesuai kotak search di mockup Laporan |
| wilayah_id | UUID (FK → wilayah) | di-resolve dari lat/long saat submit |
| deskripsi | TEXT | |
| status | ENUM('belum_ditangani','diproses','selesai') | default `belum_ditangani` |
| is_anonim | BOOLEAN | |
| jumlah_verifikasi | INTEGER | default 0, nambah tiap ada warga lain konfirmasi |
| created_at | TIMESTAMP | |

### 3.5 `verifikasi_laporan`
Mencegah satu session verifikasi laporan yang sama berkali-kali.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| laporan_id | UUID (FK → laporan_warga) | |
| session_id | UUID (FK → sesi_warga) | |
| created_at | TIMESTAMP | |
| | | **UNIQUE(laporan_id, session_id)** |

### 3.6 `skor_risiko`
Tabel hasil kalkulasi — sumber kebenaran tunggal untuk semua modul.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| wilayah_id | UUID (FK → wilayah) | |
| skor | DECIMAL(5,2) | 0–100, dipetakan ke warna (hijau <40, kuning 40–70, merah >70) |
| level | ENUM('rendah','sedang','tinggi') | derived dari skor |
| confidence_level | ENUM('kuat','lemah') | "kuat" jika ada data ABJ kader dalam 14 hari terakhir untuk wilayah tsb, else "lemah" (estimasi model umum) |
| sumber_info_text | VARCHAR(255) | mis. "Ibu Kader kesehatan Jember sudah melakukan pemeriksaan langsung..." — auto-generate dari data_abj terkait |
| dihitung_pada | TIMESTAMP | |

### 3.7 `prediksi_risiko`
Proyeksi tren 7–14 hari (grafik "Minggu ke-2 / Minggu ke-3" di mockup Peta Resiko).

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| wilayah_id | UUID (FK → wilayah) | |
| minggu_ke | INTEGER | 1, 2, 3 |
| skor_prediksi | DECIMAL(5,2) | |
| rekomendasi_text | TEXT | "Tindakan Cepat Pelindung Keluarga" di mockup |
| dihitung_pada | TIMESTAMP | |

### 3.8 `sesi_warga`
Identitas anonim warga.

| Kolom | Tipe | Keterangan |
|---|---|---|
| session_id | UUID (PK) | dikirim dari client |
| total_poin | INTEGER | default 0 |
| kuota_subscribe | INTEGER | default 1 (sesuai proposal: "biasanya hanya bisa 1 wilayah") |
| created_at | TIMESTAMP | |

### 3.9 `subscribe_wilayah`
Wilayah yang diikuti warga (untuk notifikasi).

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| session_id | UUID (FK → sesi_warga) | |
| wilayah_id | UUID (FK → wilayah) | |
| created_at | TIMESTAMP | |
| | | **UNIQUE(session_id, wilayah_id)** |

### 3.10 `notifikasi`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| session_id | UUID, nullable | untuk warga |
| kader_id | UUID, nullable | untuk kader (reminder jadwal) |
| judul | VARCHAR(150) | |
| isi | TEXT | |
| tipe | ENUM('kenaikan_risiko','cuaca_ekstrem','reminder_pemeriksaan') | |
| sudah_dibaca | BOOLEAN | default false — sesuai badge titik hijau di mockup |
| created_at | TIMESTAMP | |

### 3.11 `kader`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| nama | VARCHAR(100) | |
| email | VARCHAR(150), UNIQUE | |
| password_hash | VARCHAR(255) | bcrypt |
| wilayah_binaan_id | UUID (FK → wilayah) | |
| status_verifikasi | BOOLEAN | default false, diaktifkan admin/Dinkes |
| created_at | TIMESTAMP | |

### 3.12 `artikel`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| judul | VARCHAR(200) | |
| gambar_url | VARCHAR(255) | |
| konten | TEXT | |
| kategori | VARCHAR(50) | "DBD" / "Malaria" |
| tanggal_publish | DATE | |
| created_at | TIMESTAMP | |

### 3.13 `statistik_nasional`
Untuk kartu-kartu angka di Beranda (1.430 kematian, 7,3%, dsb.) — diisi manual/periodik dari data Kemenkes, bukan real-time.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | UUID (PK) | |
| label | VARCHAR(100) | "Rekor Tertinggi Sepanjang Sejarah" |
| nilai | VARCHAR(50) | "1.430" |
| deskripsi | TEXT | |
| tahun_data | INTEGER | |

---

## 4. Daftar Lengkap API Endpoint per Halaman

### 4.1 Landing Page (Beranda) — Publik, tanpa auth

| Method | Endpoint | Fungsi | Response |
|---|---|---|---|
| GET | `/api/statistik/ringkasan-nasional` | Ambil kartu statistik ("Rekor Tertinggi", "Indonesia Sumbang 7,3%", dst.) | `[{label, nilai, deskripsi}]` |
| GET | `/api/artikel?limit=3&sort=terbaru` | 3 artikel untuk carousel (dipakai juga di Edukasi) | `[{id, judul, gambar_url, ringkasan}]` |

### 4.2 Peta Resiko — Publik, tanpa auth

| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/wilayah?search=&level_risiko=` | Search kecamatan (autocomplete) + filter dropdown "Semua level resiko". Return GeoJSON polygon + skor tiap wilayah untuk render heatmap |
| GET | `/api/wilayah/{id}` | Detail 1 wilayah: nama, skor, level, confidence, suhu, kondisi cuaca, sumber_info_text (blok "Keadaan Wilayah" + "60%" + "Sumber Informasi" di mockup) |
| GET | `/api/wilayah/{id}/prediksi` | Data proyeksi minggu ke-2/ke-3 untuk grafik + rekomendasi tindakan |
| POST | `/api/subscribe` | Body: `{wilayah_id}`, Header: `X-Session-Id`. Tombol "Ikuti Kabar Wilayah ini". Cek kuota dulu sebelum insert — kalau kuota habis, return 403 dengan pesan yang bisa dipakai frontend untuk arahkan ke laporan (dapat kuota tambahan) |

**Detail request/response contoh:**
```
GET /api/wilayah/{id}
Response 200:
{
  "id": "uuid",
  "nama": "Kecamatan Patrang",
  "skor": 60,
  "level": "sedang",
  "confidence_level": "kuat",
  "cuaca": { "suhu": 28.5, "kondisi": "Sering Gerimis" },
  "sumber_info_text": "Ibu Kader kesehatan Jember sudah melakukan pemeriksaan langsung ke rumah-rumah warga setempat minggu ini.",
  "keadaan_text": "Kondisi lingkungan di sekitar kita saat ini cukup mendukung bagi nyamuk untuk berkembang biak...",
  "prediksi": [
    { "minggu_ke": 2, "skor": 65 },
    { "minggu_ke": 3, "skor": 58 }
  ],
  "rekomendasi": "Harap Siaga! Populasi nyamuk pembawa virus DBD diramal akan meningkat tajam..."
}
```

### 4.3 Laporan Page — Publik, tanpa auth (session anonim)

| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/geocode/reverse?lat=&lng=` | Isi otomatis kotak alamat saat tombol "Deteksi Lokasi Saya" ditekan (proxy ke Nominatim/OSM) |
| POST | `/api/laporan` | Submit laporan. **multipart/form-data**: `foto`, `latitude`, `longitude`, `deskripsi`, `nama_pelapor` (opsional), `is_anonim` (bool). Header: `X-Session-Id` |
| POST | `/api/laporan/{id}/verifikasi` | Warga lain konfirmasi laporan valid. Header: `X-Session-Id` (cek belum pernah verifikasi laporan yg sama) |
| GET | `/api/laporan/{id}/status` | Cek status tindak lanjut laporan (belum_ditangani/diproses/selesai) |

**Validasi wajib di `POST /api/laporan`:**
- MIME type: hanya `image/jpeg`, `image/png`, `image/webp`
- Ukuran maksimum: 10MB (sesuai teks di mockup: "dengan ukuran file dibawah 10MB")
- Rate limiting: maksimum misalnya 5 laporan / 10 menit per `X-Session-Id` DAN per IP (kombinasi keduanya, karena session bisa direset)
- Response harus mengembalikan poin & kuota terbaru untuk ditampilkan di halaman sukses:

```
Response 201:
{
  "laporan_id": "uuid",
  "status": "belum_ditangani",
  "poin_didapat": 10,
  "total_poin": 10,
  "kuota_subscribe_baru": 2,
  "pesan": "Berkat laporan ini, kamu dapat bonus untuk memantau 1 wilayah tambahan"
}
```

> Sesuai mockup halaman sukses, ada **2 reward berbeda**: (1) share ke sosmed — murni client-side, tidak perlu backend; (2) bonus kuota subscribe — logic ini yang harus jalan di endpoint `POST /api/laporan`. Rekomendasi: nambah kuota terjadi **langsung saat submit** (bukan menunggu verifikasi admin), supaya UX di halaman sukses sesuai mockup. Kalau nanti laporan terbukti spam/palsu, kuota bisa ditarik kembali via job moderasi.

### 4.4 Edukasi Page (utama)

| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/artikel?limit=3` | Carousel "Artikel Terkait DBD" |
| GET | `/api/statistik/fakta-dbd` | Kartu "244.409 — Jumlah kasus DBD di Indonesia sepanjang 2024" + data grafik kecil |

*Panduan 3M Plus = konten statis di frontend, tidak perlu endpoint.*

### 4.5 Edukasi — Artikel Detail

| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/artikel/{id}` | Isi lengkap 1 artikel |
| GET | `/api/artikel/{id}/terkait?limit=1` | Artikel di bagian bawah/related (opsional, kalau ada di desain final) |

**Pengisian konten artikel:** karena tidak ada waktu bikin CMS admin penuh, gunakan salah satu dari 2 opsi:
- **Opsi cepat (direkomendasikan untuk H-1):** isi tabel `artikel` langsung lewat **database seeder** Laravel, tidak perlu endpoint admin sama sekali.
- **Opsi lengkap (kalau waktu memungkinkan):** buat 1 endpoint sederhana `POST /api/admin/artikel` dengan proteksi API key statis (bukan sistem admin penuh).

### 4.6 Edukasi — Kalkulator Risiko (Kuis)

**Rekomendasi: 100% frontend, tanpa backend.** Pertanyaan dan bobot skor di-hardcode di kode Vue. Ini mempercepat development signifikan karena tim backend tidak perlu kejar 2 fitur besar sekaligus.

*Jika tim tetap ingin backend (opsional, prioritas rendah):*
| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/kuis/pertanyaan` | List pertanyaan + pilihan |
| POST | `/api/kuis/submit` | Body: array jawaban → return skor + rekomendasi |

### 4.7 Notification Page — Session anonim / Kader

| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/notifikasi?status=semua\|belum_dibaca` | List notifikasi. Header: `X-Session-Id` (warga) ATAU `Authorization: Bearer` (kader) |
| PUT | `/api/notifikasi/{id}/baca` | Tandai satu notifikasi sudah dibaca |
| PUT | `/api/notifikasi/baca-semua` | Tandai semua sudah dibaca (opsional, untuk UX) |

**Response contoh (sesuai tab "Semua (5)" / "Belum dibaca (1)" di mockup):**
```
GET /api/notifikasi?status=semua
Response 200:
{
  "total": 5,
  "belum_dibaca": 1,
  "data": [
    { "id": "uuid", "judul": "...", "isi": "...", "sudah_dibaca": false, "created_at": "2026-07-23" },
    ...
  ]
}
```

### 4.8 Statistik Page

> ⚠️ Mockup halaman ini masih kosong (belum ada konten final dari tim UI/UX). Endpoint di bawah disiapkan berdasarkan deskripsi fitur di proposal, perlu disesuaikan begitu desain final selesai.

| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/statistik/tren?wilayah_id=&range=30d` | Grafik tren kasus/ABJ untuk 1 wilayah |
| GET | `/api/statistik/perbandingan?wilayah_ids[]=&wilayah_ids[]=` | Bandingkan beberapa wilayah sekaligus |
| GET | `/api/statistik/export?format=pdf\|xlsx&wilayah_id=` | Export data (fitur ini disebut untuk kader di proposal, tapi bisa juga publik sesuai "Dashboard Statistik Publik") |

### 4.9 Kader — Login

| Method | Endpoint | Fungsi |
|---|---|---|
| POST | `/api/kader/login` | Body: `{email, password}` → return Sanctum bearer token |
| POST | `/api/kader/lupa-password` | Body: `{email}` → kirim link reset via email |
| POST | `/api/kader/reset-password` | Body: `{token, password_baru}` |
| POST | `/api/kader/logout` | Revoke token aktif |

> 🔧 **Perlu setup:** mail service untuk fitur "Lupa password?" — gunakan Mailtrap (gratis, cukup untuk demo/testing) atau SMTP Gmail untuk keperluan submission.

### 4.10 Kader — Dashboard, ABJ, Riwayat, Laporan, Notifikasi

*Semua endpoint di bawah wajib melewati middleware `auth:sanctum`.*

| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/kader/dashboard` | Overview wilayah binaan: skor terkini, ringkasan ABJ, daftar tugas pending |
| POST | `/api/kader/abj` | Submit input pemeriksaan jentik baru. Body: `{wilayah_id, jumlah_rumah_diperiksa, jumlah_rumah_positif_jentik, tanggal_pemeriksaan, catatan}` |
| GET | `/api/kader/abj/riwayat?range=` | Data grafik tren ABJ (Chart.js) |
| GET | `/api/kader/abj/perbandingan` | Bandingkan ABJ antar-RT/RW binaan (jika kader pegang lebih dari satu wilayah) |
| GET | `/api/kader/laporan/export?format=pdf\|xlsx&range=` | Generate & download rekap resmi |
| GET | `/api/kader/notifikasi` | Reminder jadwal pemeriksaan |
| PUT | `/api/kader/notifikasi/{id}/konfirmasi` | Tandai tugas pemeriksaan selesai |

---

## 5. Job Terjadwal (Laravel Task Scheduler / Cron)

Ini bagian yang paling mudah terlewat tapi krusial — **skor risiko dan cuaca TIDAK dihitung on-demand**, melainkan job background.

| Job | Jadwal | Fungsi |
|---|---|---|
| `TarikDataCuacaJob` | Tiap 1–3 jam | Panggil API cuaca eksternal untuk semua `wilayah`, insert ke `data_cuaca` |
| `HitungSkorRisikoJob` | Tiap 3–6 jam (setelah job cuaca) | Ambil data cuaca terbaru + ABJ terbaru (14 hari) + kepadatan laporan warga (7 hari) per wilayah → hitung skor → insert/update `skor_risiko` |
| `HitungPrediksiRisikoJob` | Harian | Gunakan data forecast cuaca → hitung proyeksi skor minggu ke-2 & ke-3 → insert `prediksi_risiko` |
| `GenerateNotifikasiRisikoJob` | Setelah `HitungSkorRisikoJob` | Bandingkan skor baru vs skor sebelumnya per wilayah; kalau naik signifikan (misal >15 poin atau naik level), insert notifikasi untuk semua `session_id` yang subscribe wilayah tsb |
| `GenerateNotifikasiCuacaEkstremJob` | Setelah job cuaca | Kalau curah_hujan/suhu melewati ambang batas tertentu, kirim notifikasi cuaca ekstrem |
| `ReminderPemeriksaanKaderJob` | Mingguan | Insert notifikasi reminder ke semua kader yang belum submit ABJ minggu ini |

**Formula skor risiko (contoh, bisa disesuaikan tim setelah riset lebih lanjut):**
```
skor = (bobot_cuaca × skor_cuaca_normalized)
     + (bobot_abj × (100 - abj_persen_rata2))
     + (bobot_laporan × min(jumlah_laporan_7hari × 5, 100))

# contoh bobot awal: bobot_cuaca=0.4, bobot_abj=0.4, bobot_laporan=0.2
```

**Confidence level:** `kuat` jika ada minimal 1 baris `data_abj` untuk wilayah tsb dalam 14 hari terakhir, selain itu `lemah` (murni estimasi cuaca).

---

## 6. Alur End-to-End (Sequence per Fitur Utama)

### 6.1 Alur: Warga melihat Peta Resiko
```
1. Frontend load → cek localStorage → ambil/generate session_id
2. Frontend GET /api/wilayah (tanpa filter) → render heatmap semua wilayah
3. User ketik "Patrang" di search box → GET /api/wilayah?search=Patrang
4. User klik polygon wilayah di peta → GET /api/wilayah/{id}
5. Render panel "Hasil Pemeriksaan" + grafik prediksi dari response
6. User klik "Ikuti Kabar Wilayah ini" → POST /api/subscribe {wilayah_id}
   header X-Session-Id
   → jika kuota habis: tampilkan pesan "kuota habis, laporkan genangan
     untuk dapat kuota tambahan" (arahkan ke halaman Laporan)
```

### 6.2 Alur: Warga mengirim Laporan
```
1. User buka halaman Laporan → browser minta izin geolocation
2. User klik "Deteksi Lokasi Saya" → browser Geolocation API
   → GET /api/geocode/reverse?lat=&lng= → isi kotak alamat otomatis
3. User pilih toggle "Lapor dengan identitas" / "Lapor sebagai anonim"
4. User upload foto (validasi client-side dulu: tipe & ukuran, untuk UX cepat)
5. User isi deskripsi → klik "Kirim Laporan"
6. Frontend POST /api/laporan (multipart) header X-Session-Id
7. Backend:
   a. Validasi ulang file (jangan percaya validasi client saja)
   b. Simpan foto ke storage (local/S3-compatible)
   c. Resolve wilayah_id dari lat/long (point-in-polygon query PostGIS)
   d. Insert row laporan_warga
   e. Update sesi_warga: total_poin += 10, kuota_subscribe += 1 (contoh)
   f. Return response dengan poin & kuota terbaru
8. Frontend redirect ke halaman "Laporan Anda Terkirim"
   → tampilkan poin & opsi "Pilih Wilayah" (bonus kuota)
9. (Async, tidak blocking) job background nanti akan ikut hitung
   laporan ini sebagai bagian dari skor_risiko wilayah tsb
```

### 6.3 Alur: Kader Input ABJ
```
1. Kader login → POST /api/kader/login → simpan bearer token
2. GET /api/kader/dashboard (header Authorization: Bearer) → tampilkan overview
3. Kader buka /kader/abj → isi form (jumlah diperiksa, jumlah positif)
4. Frontend hitung preview ABJ% secara live di client (UX), lalu
   POST /api/kader/abj → backend hitung ulang & simpan (source of truth)
5. Job HitungSkorRisikoJob nanti akan otomatis pakai data ABJ terbaru
   ini di kalkulasi skor berikutnya
```

### 6.4 Alur: Notifikasi otomatis sampai ke warga
```
1. Job TarikDataCuacaJob jalan tiap beberapa jam
2. Job HitungSkorRisikoJob jalan setelahnya, deteksi kenaikan skor
   di wilayah "Patrang" dari 45 → 68 (level: sedang → tinggi)
3. Job GenerateNotifikasiRisikoJob query semua session_id yang
   subscribe wilayah Patrang → insert notifikasi utk masing2
4. Warga buka web lagi → frontend cek localStorage session_id
5. GET /api/notifikasi?status=belum_dibaca header X-Session-Id
6. Badge notifikasi (ikon lonceng) tampilkan angka unread
```

---

## 7. Tech Stack & Setup Checklist untuk Anggota 2

| Komponen | Pilihan | Catatan |
|---|---|---|
| Backend framework | Laravel (PHP) | sesuai proposal |
| Auth kader | Laravel Sanctum | token-based |
| Database | PostgreSQL | + ekstensi **PostGIS** (untuk polygon wilayah & query spasial) |
| API Cuaca | OpenWeatherMap (tier gratis) atau BMKG | daftar API key di awal, cek rate limit tier gratis |
| Geocoding | Nominatim (OpenStreetMap, gratis) | untuk reverse geocode di form Laporan |
| Storage foto | Local disk (dev) → pindah ke S3-compatible (mis. Cloudflare R2/DO Spaces) kalau sempat | pastikan validasi MIME & ukuran di server, bukan cuma client |
| Mail (lupa password) | Mailtrap (testing) | cukup untuk demo, tidak perlu domain email sendiri |
| Job scheduler | Laravel Task Scheduler + cron OS | pastikan cron server aktif di hosting produksi |
| Deployment backend | VPS/PaaS yang support PHP + PostgreSQL + cron | Railway/Render tier gratis bisa jadi opsi cepat |

**Urutan setup yang disarankan (hari pertama):**
1. Init project Laravel + koneksi PostgreSQL + aktifkan PostGIS
2. Buat semua migration sesuai skema di Bagian 3
3. Seeder data wilayah (minimal beberapa kecamatan contoh dengan geometri sederhana)
4. Setup Sanctum untuk auth kader
5. Implementasi endpoint Peta Resiko dulu (fitur inti, bobot penilaian terbesar)
6. Baru lanjut ke Laporan, lalu Kader Dashboard, baru fitur pendukung lainnya

---

## 8. Prioritas Implementasi (karena waktu sangat terbatas)

| Prioritas | Modul | Alasan |
|---|---|---|
| 🔴 P0 — Wajib | Peta Resiko + skor risiko + job cuaca | Fitur inti, bobot rubrik terbesar (25% Fungsionalitas Heatmap & CRUD Dashboard) |
| 🔴 P0 — Wajib | Laporan warga (submit + upload foto) | Fitur crowdsourcing = pembeda utama proposal |
| 🔴 P0 — Wajib | Kader login + input ABJ | Disebut eksplisit di rubrik penilaian |
| 🟡 P1 — Penting | Notifikasi (minimal versi sederhana) | Ada di proposal, tapi bisa versi ringkas dulu |
| 🟡 P1 — Penting | Subscribe wilayah + kuota | Terkait erat dengan gamifikasi yang jadi nilai jual |
| 🟢 P2 — Bisa disederhanakan | Kalkulator risiko (kuis) | **Rekomendasi: frontend-only**, tidak butuh backend |
| 🟢 P2 — Bisa disederhanakan | Artikel edukasi | Seed manual, tidak perlu CMS admin |
| 🟢 P2 — Bisa disederhanakan | Statistik page | Desain belum final — tunda sampai UI/UX selesaikan mockup |
| ⚪ Opsional | Export PDF/Excel kader | Bagus untuk nilai tambah tapi bisa jadi fitur terakhir kalau waktu cukup |

---

*Dokumen ini disusun berdasarkan analisis 10 mockup UI (Landing, Peta Resiko, Laporan + halaman sukses, Notifikasi, Edukasi + Artikel + Kuis, Statistik, Login Kader) dan proposal TIC 9.0. Perlu direvisi begitu ada perubahan desain, terutama untuk halaman Statistik yang belum final.*
