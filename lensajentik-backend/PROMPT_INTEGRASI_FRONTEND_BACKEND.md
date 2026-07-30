# Prompt untuk Antigravity — Audit & Integrasi Frontend-Backend LensaJentik

> **Cara pakai:** Copy seluruh isi file ini, paste ke chat Antigravity di project frontend kamu. Biarkan agent baca dulu semuanya sebelum mulai kerja.

---

## KONTEKS PROJECT

Aku sedang membangun **LensaJentik**, platform Web-GIS untuk deteksi dini risiko wabah DBD/Malaria (kompetisi TIC 9.0). Backend Laravel 13 + PostgreSQL sudah selesai dibangun dan sudah **live di production** (Railway). Sekarang aku butuh kamu menyambungkan frontend ini ke backend tersebut, dan mengecek fitur apa saja yang masih pakai data dummy/mock.

**Base URL API production:**
```
https://backendlensajentik-production.up.railway.app/api
```
*(Ganti dengan URL Railway asli sebelum kirim prompt ini — cek di Railway → service Laravel → Settings → Networking)*

**Autentikasi:** Bearer Token via Laravel Sanctum. Setelah login/register, backend mengembalikan `token` yang harus disimpan (localStorage/cookie) dan disertakan di header tiap request yang butuh auth:
```
Authorization: Bearer <token>
Accept: application/json
```

---

## ATURAN KERJA — WAJIB DIIKUTI URUT

**JANGAN langsung mulai coding.** Ikuti tahapan ini secara berurutan:

### Tahap 1 — AUDIT (hanya baca, jangan ubah kode dulu)

Scan **seluruh** halaman, komponen, dan fitur di frontend ini satu per satu. Untuk masing-masing, tentukan statusnya:

- ✅ **Sudah terhubung ke backend asli** (fetch/axios ke API beneran, bukan data statis)
- 🟡 **Masih pakai dummy/mock/hardcoded data** (array statis di kode, data placeholder, `console.log` doang, dst)
- 🔴 **Backend endpoint yang dibutuhkan BELUM ADA** (lihat daftar endpoint yang tersedia di bawah — kalau fitur frontend butuh sesuatu yang gak ada di daftar itu, catat sebagai kebutuhan endpoint baru)

Cek juga fungsi-fungsi kecil yang sering kelewat kayak: search/pencarian, filter, pagination, loading state, error handling, empty state (data kosong), validasi form sebelum submit, redirect setelah login/logout.

### Tahap 2 — BUAT LAPORAN (tulis dulu, jangan eksekusi)

Setelah audit selesai, tulis laporan lengkap dalam format tabel per halaman/fitur:

| Halaman/Komponen | Status | Endpoint yang dipakai/dibutuhkan | Catatan |
|---|---|---|---|
| contoh: Halaman Login | ✅ | POST /api/auth/login | Sudah OK |
| contoh: Peta Risiko | 🟡 | GET /api/skor-risiko/peta | Masih pakai array dummy 5 titik |
| contoh: Fitur Chat | 🔴 | — | Backend belum punya fitur ini sama sekali |

Di akhir laporan, buat **ringkasan prioritas**: mana yang tinggal disambungkan (🟡, paling cepat dikerjain), dan mana yang butuh endpoint baru dulu (🔴, harus dikabarin ke aku dulu karena aku yang pegang backend).

### Tahap 3 — TUNGGU KONFIRMASI

**Setelah laporan Tahap 2 selesai, STOP.** Jangan langsung mulai coding. Tampilkan laporannya ke aku dulu, aku akan review dan kasih tau mana yang boleh langsung dikerjakan sekarang, dan mana yang perlu nunggu aku update backend dulu.

---

## DAFTAR ENDPOINT BACKEND YANG SUDAH TERSEDIA

Semua response dalam format JSON. Field bertanda 🔒 butuh header `Authorization: Bearer <token>`.

### Auth
| Method | Endpoint | Auth | Body/Query | Keterangan |
|---|---|---|---|---|
| POST | `/auth/register` | - | `name, email, password, password_confirmation, phone?, wilayah_kode?` | Role otomatis "warga" |
| POST | `/auth/login` | - | `email, password` | Return `{user, token}` |
| POST | `/auth/logout` | 🔒 | - | Cabut token aktif |
| GET | `/auth/me` | 🔒 | - | Data user + wilayah tugas |

### Wilayah
| Method | Endpoint | Auth | Query | Keterangan |
|---|---|---|---|---|
| GET | `/wilayah` | - | `tingkat, parent_kode` | List/filter wilayah |
| GET | `/wilayah/search` | - | `q` (min 3 char) | Cari nama kabupaten/kecamatan |
| GET | `/wilayah/{kode}` | - | - | Detail + breadcrumb parent |
| GET | `/wilayah/{kode}/desa` | - | - | List desa (lazy-load, auto-cache) |

### Cuaca & Skor Risiko
| Method | Endpoint | Auth | Query | Keterangan |
|---|---|---|---|---|
| GET | `/cuaca/{kode_wilayah}` | - | - | Cuaca hari ini + forecast 14 hari |
| GET | `/skor-risiko/{kode}` | - | `jenis` (dbd/malaria) | Skor + prediksi 14 hari, ada `faktor_perhitungan` untuk breakdown |
| GET | `/skor-risiko/peta` | - | `tingkat, parent_kode, jenis, level_risiko?, tanggal?` | Data buat render peta choropleth/marker |

### ABJ Kader
| Method | Endpoint | Auth | Body/Query | Keterangan |
|---|---|---|---|---|
| POST | `/abj` | 🔒 | `wilayah_kode, tanggal_pemeriksaan, jumlah_rumah_diperiksa, jumlah_rumah_positif_jentik, catatan?` | Input ABJ, `abj_persen` dihitung otomatis backend |
| GET | `/abj` | 🔒 | `wilayah_kode` | Riwayat ABJ per wilayah |
| GET | `/abj/saya` | 🔒 | - | Riwayat input milik user login |

### Laporan Warga
| Method | Endpoint | Auth | Body/Query | Keterangan |
|---|---|---|---|---|
| POST | `/laporan-warga` | opsional | `form-data: wilayah_kode, latitude, longitude, foto (file), deskripsi?` | Boleh anonim (tanpa token = user_id null, gak dapat poin) |
| GET | `/laporan-warga` | - | `wilayah_kode?, status?` | List + pagination |
| GET | `/laporan-warga/{id}` | - | - | Detail + daftar verifikasi |
| POST | `/laporan-warga/{id}/verifikasi` | 🔒 | - | Konfirmasi komunitas, +5 poin |
| PATCH | `/laporan-warga/{id}/status` | 🔒 (role kader/admin) | `status` | Update status penanganan |

### Subscribe Wilayah & Notifikasi
| Method | Endpoint | Auth | Body/Query | Keterangan |
|---|---|---|---|---|
| GET | `/subscribe-wilayah` | 🔒 | - | List + info kuota (`kuota`, `terpakai`) |
| POST | `/subscribe-wilayah` | 🔒 | `wilayah_kode` | Gagal kalau kuota penuh |
| DELETE | `/subscribe-wilayah/{wilayah_kode}` | 🔒 | - | Unsubscribe |
| GET | `/notifikasi` | 🔒 | - | List + `belum_dibaca` count |
| PATCH | `/notifikasi/{id}/baca` | 🔒 | - | Tandai 1 dibaca |
| PATCH | `/notifikasi/baca-semua` | 🔒 | - | Tandai semua dibaca |

### Edukasi (publik, tanpa login)
| Method | Endpoint | Auth | Body/Query | Keterangan |
|---|---|---|---|---|
| GET | `/edukasi` | - | `tipe?` (artikel/panduan) | List + pagination |
| GET | `/edukasi/{slug}` | - | - | Detail konten |
| GET | `/edukasi/kuis/pertanyaan` | - | - | Daftar pertanyaan kuis kalkulator risiko |
| POST | `/edukasi/kuis/hitung` | - | `jawaban: {id_pertanyaan: value}` | Return skor + level + rekomendasi |

### Statistik (publik, tanpa login)
| Method | Endpoint | Auth | Query | Keterangan |
|---|---|---|---|---|
| GET | `/statistik/ringkasan` | - | `wilayah_kode` | Tren skor risiko, ABJ, laporan per status |
| GET | `/statistik/bandingkan` | - | `wilayah_kode[]` (2-10 kode) | Bandingkan beberapa wilayah sekaligus |

### Export
| Method | Endpoint | Auth | Query | Keterangan |
|---|---|---|---|---|
| GET | `/export/abj/excel` | 🔒 (role kader/admin) | `wilayah_kode, dari?, sampai?` | Download .xlsx |
| GET | `/export/abj/pdf` | 🔒 (role kader/admin) | `wilayah_kode, dari?, sampai?` | Download .pdf |

### Admin
| Method | Endpoint | Auth | Body/Query | Keterangan |
|---|---|---|---|---|
| GET | `/admin/users` | 🔒 (role admin) | `role?, wilayah_kode?` | List user + count laporan |
| POST | `/admin/users` | 🔒 (role admin) | `name, email, password, role, wilayah_kode?, phone?` | Bikin akun kader/admin |
| GET | `/admin/users/{id}/kinerja` | 🔒 (role admin) | - | Statistik kinerja 1 kader |
| PATCH | `/admin/users/{id}` | 🔒 (role admin) | field yang mau diubah | Edit user |
| DELETE | `/admin/users/{id}` | 🔒 (role admin) | - | Nonaktifkan (soft) |
| GET | `/admin/dashboard/ringkasan` | 🔒 (role admin) | `wilayah_kode` | Sama seperti statistik publik, versi admin |
| GET | `/admin/dashboard/bandingkan` | 🔒 (role admin) | `wilayah_kode[]` | - |

**Role yang tersedia:** `warga`, `kader`, `admin_puskesmas`, `admin_dinkes`

---

## KETERBATASAN YANG PERLU DIKETAHUI (bukan bug, memang begitu desainnya)

1. **Wilayah cuma sampai level "desa"**, tidak ada RT/RW (keterbatasan sumber data resmi).
2. **Koordinat wilayah itu titik tunggal (hasil geocoding)**, bukan polygon batas wilayah. Kalau frontend butuh render choropleth map beneran (area berwarna), ini butuh sumber data GeoJSON terpisah yang belum ada — laporkan kalau ini genuinely dibutuhkan.
3. **Data desa di-generate on-demand** — endpoint `/wilayah/{kode}/desa` mungkin agak lambat di percobaan pertama (fetch dari API luar), tapi cepat di percobaan berikutnya (sudah di-cache).
4. **Reset password belum ada endpointnya.** Kalau frontend butuh fitur "lupa password", ini salah satu yang perlu dikabarin ke aku untuk dibikin di backend.

---

## SEKALI LAGI: JANGAN LANGSUNG CODING

Mulai dari Tahap 1 (audit) dan Tahap 2 (laporan) dulu. Tunggu aku konfirmasi sebelum eksekusi perubahan kode apapun.
