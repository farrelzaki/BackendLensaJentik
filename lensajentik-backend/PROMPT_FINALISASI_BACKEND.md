# Prompt untuk Antigravity — Audit & Finalisasi Backend LensaJentik

> **Cara pakai:** Buka project backend Laravel ini di Antigravity. Sebelum paste prompt ini, lampirkan juga 2 file dokumen: `RANCANGAN_LOMBA_TIC.md` dan `PROJECT_CONTEXT_LensaJentik.md` ke chat yang sama (kalau ada di repo/folder, kalau tidak, copy-paste isinya) — itu jadi acuan "tujuan akhir" yang harus dicek kesesuaiannya. Baru paste seluruh isi prompt ini.

---

## KONTEKS

Backend Laravel 13 + PostgreSQL untuk **LensaJentik** (platform Web-GIS deteksi dini DBD/Malaria) sudah dibangun bertahap dan sudah **live di production** (Railway). Sebagian besar fitur inti sudah jalan dan sudah dites manual satu-satu lewat Postman. Tapi karena dibangun bertahap dalam beberapa sesi, ada kemungkinan:

- Beberapa endpoint gak konsisten satu sama lain (format response beda-beda, validasi gak lengkap)
- Ada alur (flow) yang secara teknis "jalan" tapi belum dites end-to-end sebagai satu kesatuan cerita user
- Ada beberapa gap fitur yang sempat ketauan tapi belum sempat dikerjain
- Ada kemungkinan bug tersembunyi di logic yang jarang ditest (edge case)

Tujuanmu: pastikan backend ini **benar-benar lengkap dan solid**, bukan cuma "kelihatan jalan pas ditest sepotong-sepotong".

---

## TEMUAN DARI AUDIT FRONTEND — SUDAH DIKONFIRMASI, WAJIB DIKERJAKAN

Tim frontend sudah melakukan audit integrasi dan menemukan 3 gap di sisi backend. Ini bukan lagi dugaan, tapi **kebutuhan pasti** — masukkan sebagai bagian dari Tahap 1 audit, tapi untuk 2 item pertama, tidak perlu didebat lagi apakah perlu dibuat atau tidak — buat spesifikasinya sebagai berikut:

### 1. Endpoint update profil sendiri (WAJIB dibuat)

Saat ini `PATCH /admin/users/{id}` cuma bisa diakses admin buat edit user lain. User (role apapun: warga/kader/admin) butuh cara edit profilnya **sendiri**.

Buat endpoint baru:
```
PATCH /api/auth/update-profile
Auth: wajib login (role apapun)
Body: name? (string), phone? (string, nullable),
      current_password? (wajib diisi HANYA JIKA mau ganti password),
      password? (min 8, wajib ada password_confirmation kalau diisi)
```
Validasi: kalau `password` diisi, `current_password` wajib dicocokkan dulu ke password lama sebelum diizinkan ganti (pakai `Hash::check`). Kalau `current_password` salah, tolak dengan pesan jelas. Field `email` dan `role` **tidak boleh** diubah lewat endpoint ini (biar gak ada celah user naikin role sendiri jadi admin).

Return: data user yang sudah terupdate (tanpa expose `password`).

### 2. Flow lupa password / reset password (WAJIB dibuat)

Karena project ini API-only (bukan pakai default Laravel web auth scaffolding), implementasikan pakai `Illuminate\Support\Facades\Password` broker (Laravel sudah punya infrastrukturnya bawaan, termasuk tabel `password_reset_tokens` yang biasanya sudah otomatis ter-generate dari migration default awal — cek dulu apakah tabel itu sudah ada di database, kalau belum, buat migration-nya).

Buat 2 endpoint:
```
POST /api/auth/forgot-password
Body: email
Aksi: generate token reset, kirim email berisi token/link ke user (pakai Mail/Resend yang sudah terkonfigurasi). 
Return: pesan generik ("Kalau email terdaftar, link reset sudah dikirim") — JANGAN bocorkan apakah email itu terdaftar atau tidak, ini demi keamanan (mencegah orang enumerasi email terdaftar).

POST /api/auth/reset-password
Body: email, token, password, password_confirmation
Aksi: validasi token cocok dan belum expired, update password user, invalidate token itu setelah dipakai.
Return: pesan sukses, atau error kalau token invalid/expired.
```

Buatkan juga template email sederhana untuk reset password (mirip `resources/views/emails/notifikasi.blade.php` yang sudah ada, boleh dicontek stylingnya) — isinya link/kode reset dan instruksi singkat.

### 3. GeoJSON polygon batas wilayah — KEPUTUSAN: TIDAK PERLU DIKERJAKAN SEKARANG

Frontend sudah tau keterbatasan ini dan sudah punya solusi sementara (gambar bounding box dari titik koordinat). **Jangan alokasikan waktu untuk ini** kecuali nanti eksplisit diminta lagi — sumber data GeoJSON batas administratif Indonesia itu scope besar tersendiri (butuh sumber data terpisah, bukan sekadar nambah endpoint) dan bukan prioritas untuk deadline sekarang.

---

## ATURAN KERJA — WAJIB DIIKUTI URUT, JANGAN LONCAT TAHAP

### Tahap 1 — AUDIT KESESUAIAN DENGAN DOKUMEN TUJUAN

Baca `RANCANGAN_LOMBA_TIC.md` dan `PROJECT_CONTEXT_LensaJentik.md` yang dilampirkan. Untuk **setiap** fitur yang disebut di kedua dokumen itu, cek di kode backend:

- ✅ Sudah ada dan sesuai
- 🟡 Sudah ada tapi ada penyimpangan dari dokumen (jelaskan penyimpangannya apa)
- 🔴 Belum ada sama sekali

### Tahap 2 — AUDIT ALUR END-TO-END (bukan cuma per-endpoint)

Jangan cuma cek endpoint satu-satu secara terisolasi. Telusuri **alur cerita user secara utuh**, dari awal sampai akhir, dan pastikan tiap langkah nyambung logically ke langkah berikutnya. Minimal telusuri alur-alur ini:

1. **Warga baru** → register → login → lihat peta risiko → subscribe wilayah → cek kuota subscribe sesuai role default → kirim laporan warga (dengan foto) → dapat poin → poin bertambah → kuota subscribe ikut nambah otomatis → dapat notifikasi reward → cek notifikasi masuk
2. **Warga anonim** → kirim laporan tanpa login sama sekali → laporan tersimpan dengan `user_id` null → tidak error di manapun karena `user` kosong
3. **Kader** → login → input ABJ → cek riwayat ABJ miliknya sendiri → export laporan ABJ ke Excel/PDF → cek nilai `abj_persen` terhitung benar
4. **Admin** → login → bikin akun kader baru → cek kader baru itu bisa login → admin nonaktifkan akun kader itu (`is_active = false`) → **pastikan kader yang dinonaktifkan TIDAK BISA login lagi** (cek ini secara khusus, ada kemungkinan besar ini belum diimplementasi di `AuthController@login`)
5. **Sistem otomatis** → jalankan `skor-risiko:refresh` → skor risiko berubah levelnya → user yang subscribe wilayah itu dapat notifikasi in-app DAN email → cek `NotificationService` beneran terpanggil, bukan cuma logic dihitung tapi notifikasi gak pernah terkirim
6. **Verifikasi komunitas** → user A kirim laporan → user B verifikasi laporan itu → `jumlah_verifikasi` bertambah → user B dapat poin → **user B tidak bisa verifikasi laporan yang sama 2 kali** (cek constraint unique-nya beneran mencegah ini di level database, bukan cuma di level aplikasi)
7. **Kuis edukasi** → ambil daftar pertanyaan → kirim jawaban → hitung skor → hasil rekomendasi sesuai level

Untuk tiap alur di atas, tandai bagian mana yang **belum pernah benar-benar dites end-to-end**, dan cek langsung ke kode apakah logic-nya benar-benar menyambung atau ada celah.

### Tahap 3 — AUDIT KEAMANAN & KUALITAS TEKNIS

Cek poin-poin spesifik ini (beberapa sudah diketahui jadi gap dari sesi sebelumnya, pastikan sudah/belum dibenerin):

- [ ] **Rate limiting di endpoint auth** (`/auth/login`, `/auth/register`) — cek apakah ada throttle middleware, kalau belum ada, ini celah brute-force
- [ ] **`is_active` dicek saat login** — user nonaktif harus ditolak login
- [ ] **Endpoint update profil sendiri** — sudah wajib dibuat, lihat spesifikasi lengkap di bagian atas ("Temuan dari Audit Frontend")
- [ ] **Endpoint reset password / lupa password** — sudah wajib dibuat, lihat spesifikasi lengkap di bagian atas ("Temuan dari Audit Frontend")
- [ ] Semua endpoint yang butuh auth beneran dilindungi middleware `auth:sanctum` — scan `routes/api.php` cari kebocoran (endpoint yang seharusnya protected tapi lupa dikasih middleware)
- [ ] Semua endpoint yang butuh role tertentu beneran dilindungi middleware `role:` — cek gak ada endpoint admin yang bisa diakses role biasa
- [ ] Validasi input di semua Controller — cek gak ada endpoint yang nerima input tanpa `$request->validate()`
- [ ] Response error format konsisten di semua controller (semua pakai format JSON yang sama: `{message, errors?}`)
- [ ] N+1 query problem — cek query yang loop manggil relasi tanpa eager load (`with()`)
- [ ] File upload (foto laporan warga) — cek validasi ukuran & tipe file sudah benar, dan penanganan kalau upload ke Cloudinary gagal (jangan sampai data laporan setengah tersimpan tapi foto gagal upload, atau sebaliknya)

### Tahap 4 — CEK OPERASIONAL PRODUCTION

- [ ] Semua environment variable yang dibutuhkan sudah ada di Railway (`DB_*`, `CLOUDINARY_*`, `MAIL_MAILER=resend` + `RESEND_API_KEY`, `APP_KEY`, dll) — list semua yang dipakai kode (`env('...')`) lalu silang-cek harus ada semua
- [ ] Migration paling baru sudah pernah dijalankan di database production (bukan cuma di lokal)
- [ ] Scheduled command (`skor-risiko:refresh`, `reminder-kader:cek`) terdaftar di `routes/console.php` dengan jadwal yang masuk akal
- [ ] `APP_DEBUG=false` di production (jangan bocorin stack trace error ke publik)
- [ ] CORS mengizinkan request dari domain frontend

### Tahap 5 — TULIS LAPORAN (jangan eksekusi dulu)

Rangkum semua temuan dari Tahap 1-4 ke dalam 1 laporan terstruktur, dengan format:

```
## Temuan Kritis (harus dibenerin sebelum submit lomba)
- [daftar dengan penjelasan singkat + lokasi file]

## Temuan Sedang (sebaiknya dibenerin kalau waktu ada)
- [daftar]

## Temuan Minor / Catatan (boleh diabaikan atau dijelaskan sebagai keterbatasan di proposal)
- [daftar]
```

Untuk tiap temuan, sertakan: **nama file & lokasi**, **apa masalahnya**, **saran perbaikannya**, dan **estimasi seberapa berisiko kalau gak dibenerin** (misal: "rendah, cuma edge case jarang terjadi" vs "tinggi, ini akan kelihatan kalau juri coba fitur X").

### Tahap 6 — TUNGGU KONFIRMASI

**Setelah laporan Tahap 5 selesai, STOP total.** Jangan mulai ubah kode apapun. Tunjukkan laporan itu dulu ke aku. Aku akan tentukan urutan prioritas mana yang dikerjain duluan.

### Tahap 7 — EKSEKUSI PERBAIKAN (hanya setelah aku konfirmasi)

Kerjakan **satu per satu**, bukan sekaligus borongan. Tiap selesai 1 perbaikan:
1. Jelaskan apa yang diubah dan kenapa
2. Kalau ada migration baru, sebutkan command yang perlu dijalankan
3. Kasih cara test manual buat verifikasi perbaikan itu beneran berhasil sebelum lanjut ke perbaikan berikutnya
4. **Jangan ubah/hapus fitur yang sudah confirmed jalan** hanya karena "dirapikan" — fokus cuma ke item yang sudah dikonfirmasi masuk prioritas

---

## YANG TIDAK BOLEH DILAKUKAN

- Jangan refactor besar-besaran struktur project yang sudah jalan hanya atas dasar "best practice", kecuali itu memang termasuk temuan kritis
- Jangan ganti package/library yang sudah terpasang dan berfungsi (Sanctum, Cloudinary, Resend, Maatwebsite Excel, DomPDF) kecuali ada alasan kuat
- Jangan hapus data seed yang sudah ada (data wilayah 38 provinsi dkk) — ini butuh waktu lama buat digenerate ulang
- Jangan push langsung ke production tanpa aku review dulu perubahannya

---

Mulai dari Tahap 1. Ingat: audit dan laporan dulu, eksekusi belakangan setelah aku konfirmasi.
