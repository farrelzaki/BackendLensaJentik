# PROJECT CONTEXT — LensaJentik

> Dokumen ini adalah konteks proyek lengkap untuk platform **LensaJentik**, disusun berdasarkan Rancangan Lomba TIC 9.0 (revisi), User Flow (Alur Pengguna 1: Warga Publik & Alur Pengguna 2: Kader Kesehatan), dan rancangan UI (wireframe/mockup) yang telah dibuat. Dokumen ini dimaksudkan untuk dipakai sebagai konteks acuan saat melakukan prompting proses coding (frontend & backend), sehingga setiap fitur, alur, dan tampilan yang dibangun harus konsisten dengan isi dokumen ini.

---

## 1. Ringkasan Proyek

**Nama Produk:** LensaJentik
**Kompetisi:** Technology Innovative Challenge (TIC) 9.0 2026 — Universitas Jember
**Kategori Penilaian:** 40% Proposal, 60% Website
**Deadline Tahap 1:** 31 Juli 2026
**Tim:** 3 orang — Ketua Tim (Project Manager & Technical Writer), Anggota 1 (UI/UX Designer & Frontend Developer), Anggota 2 (Backend, GIS Specialist & Data Engineer)

**Inti Solusi:** LensaJentik adalah platform web berbasis **Web-GIS** untuk pemetaan dan mitigasi risiko penyebaran **DBD (Demam Berdarah Dengue)** dan **Malaria**. Platform ini menggabungkan tiga sumber data utama:
1. **Data cuaca real-time** (suhu, kelembapan, curah hujan) untuk menghitung skor risiko wilayah berbasis model prediktif.
2. **Data lapangan dari kader kesehatan** — Angka Bebas Jentik (ABJ) per RT/RW, menggantikan pencatatan manual berbasis kertas.
3. **Laporan crowdsourcing dari warga** — foto genangan air liar dengan geotagging, sebagai sinyal tambahan titik rawan.

Ketiga sumber ini disatukan menjadi **peta risiko interaktif** berkode warna (rendah/sedang/tinggi) yang dapat diakses publik tanpa login, dilengkapi indikator kepercayaan data (*confidence level*), prediksi tren 7–14 hari, sistem notifikasi, gamifikasi kontribusi warga, serta dashboard khusus kader kesehatan untuk input dan pelaporan data ABJ ke Puskesmas/Dinkes.

---

## 2. Prinsip Desain & Keputusan Penting (WAJIB DIPATUHI)

Beberapa keputusan berikut merupakan **revisi resmi** dari rancangan awal dan harus menjadi acuan utama saat membangun fitur — jangan menyimpang dari keputusan ini:

1. **Tidak ada dashboard terpisah untuk Dinkes/Peneliti.** Seluruh kebutuhan analitik untuk pihak berwenang digabung ke dalam satu **Halaman Statistik** yang dapat diakses oleh **seluruh pengguna publik tanpa login atau role khusus**.
2. **Tidak ada fitur "Export data mentah untuk pihak berwenang"** di Halaman Statistik — kebutuhan ekspor data resmi ke Dinkes sudah diakomodasi lewat fitur **Export Laporan** di Dashboard Kader (format PDF/Excel).
3. **Akses dua peran (multi-role) tanpa sistem akun ganda yang rumit:**
   - **Warga publik** mengakses seluruh fitur publik **langsung tanpa login/registrasi**.
   - **Kader kesehatan** mengakses dashboard khususnya melalui **ikon akun di navigation bar landing page** → mengarah ke **halaman login khusus kader** (bukan menu terpisah di navigasi utama warga).
4. **Onboarding interaktif** hanya tampil otomatis pada kunjungan pertama pengguna ke website, dan selanjutnya dapat diakses ulang secara manual melalui tombol bantuan di landing page.
5. Navigation bar landing page warga publik berisi: **Peta Risiko, Statistik, Laporan, Notifikasi, Edukasi**, plus **ikon Login Kader**. Navbar dirancang ringkas — semua fungsi utama harus dapat dijangkau maksimal dalam 1 klik dari landing page.
6. Dashboard Kader akan menampilkan **ringkasan wilayah binaan** (jumlah RT/RW yang menjadi tanggung jawab kader) dan **grafik tren singkat ABJ**, dengan pengingat pemeriksaan, notifikasi, dan setting yang mudah dijangkau, cepat sekali klik menuju detail masing-masing.

---

## 3. Target Pengguna (2 Persona Utama)

### Persona 1 — Warga Publik
Pengguna umum yang mengakses website **tanpa perlu login**. Tujuannya: memantau risiko wilayah tempat tinggal/beraktivitas, melapor genangan air, mendapat edukasi pencegahan, dan berkontribusi melalui gamifikasi.

### Persona 2 — Kader Kesehatan
Petugas lapangan (kader jumantik/posyandu) yang **login** melalui halaman khusus. Tujuannya: menggantikan pencatatan ABJ berbasis kertas dengan input digital, memantau tren wilayah kerja, dan mengekspor laporan resmi ke Puskesmas/Dinkes.

---

## 4. Arsitektur Informasi & Peta Situs (Sitemap)

### 4.1 Warga Publik (tanpa login)
```
Landing Page (/)
├── Peta Risiko (/peta-resiko)
│   ├── Web-GIS interaktif (kode warna rendah/sedang/tinggi)
│   ├── Confidence level indicator per wilayah
│   ├── Prediksi tren 7–14 hari
│   ├── Filter peta (jenis penyakit / rentang waktu / tingkat risiko)
│   └── Pencarian wilayah → Subscribe wilayah
├── Laporan (/laporan)
│   ├── Form lapor genangan (foto + geolocation + deskripsi)
│   ├── Halaman "Setelah Kirim Laporan" (status tindak lanjut + poin + share twibbon)
├── Statistik (/statistik)
│   ├── Dashboard statistik wilayah (tren kasus, ABJ, laporan warga)
│   └── Perbandingan antarwilayah
├── Notifikasi (/notifikasi)
│   ├── Notifikasi kenaikan risiko wilayah subscribe
│   ├── Notifikasi cuaca ekstrem
│   └── Notifikasi reward (penambahan kuota subscribe)
├── Edukasi (/edukasi)
│   ├── Panduan pencegahan DBD/Malaria (artikel statis: 3M plus, kenali gejala)
│   ├── Kalkulator risiko personal (kuis singkat)
│   └── Artikel/berita kesehatan (agregasi sumber terpercaya)
├── Onboarding (modal/overlay, tampil otomatis di kunjungan pertama, dapat dipanggil ulang)
└── Ikon Akun → Login Kader (/kader/login)
```

### 4.2 Kader Kesehatan (login khusus)
```
Login Kader (/kader/login)
└── Dashboard Kader (/kader/dashboard)
    ├── Kelola Data ABJ (/kader/abj)
    │   ├── Pilih wilayah kerja RT/RW
    │   ├── Form digital input ABJ (jumlah rumah diperiksa, jumlah rumah positif jentik)
    │   └── Simpan Riwayat
    ├── Riwayat & Tren (/kader/riwayat)
    │   ├── Riwayat input per wilayah
    │   ├── Grafik tren mingguan (Chart.js, auto-update tiap input baru)
    │   └── Perbandingan antarwilayah (performa ABJ lintas RT/RW)
    ├── Laporan (/kader/laporan)
    │   ├── Pilih rentang data yang ingin direkap
    │   └── Export PDF/Excel untuk pelaporan resmi ke Puskesmas/Dinkes
    ├── Notifikasi (/kader/notifikasi)
    │   ├── Reminder rutin jadwal pemeriksaan jentik
    │   └── Tandai jadwal pemeriksaan sebagai selesai
    └── Pengaturan (/kader/pengaturan)
```

---

## 5. Rincian Halaman & Fitur — Warga Publik

### 5.1 Landing Page
- Hero section berisi ringkasan value proposition LensaJentik (pemetaan risiko DBD/Malaria berbasis data cuaca + kader + crowdsourcing warga).
- Navigation bar tetap terlihat (sticky) berisi menu: Peta Risiko, Statistik, Laporan, Notifikasi, Edukasi, serta ikon akun (Login Kader) dan ikon notifikasi.
- Bagian pengenalan fitur unggulan (cards) yang mengarahkan ke masing-masing halaman.
- Tombol bantuan untuk memicu ulang onboarding interaktif kapan saja.
- Modal onboarding otomatis tampil hanya pada kunjungan pertama, menjelaskan fungsi utama tiap menu navbar dan tombol akun kader.

### 5.2 Peta Risiko (`/peta-resiko`)
Fitur inti dari LensaJentik. Berdasarkan alur pengguna, urutan interaksi:
1. Sistem menampilkan tampilan **Web-GIS interaktif**.
2. Peta menampilkan **kode warna per wilayah**: hijau (rendah), kuning (sedang), merah (tinggi) — dihitung dari skor risiko berbasis cuaca (suhu, kelembapan, curah hujan real-time via API).
3. User dapat melihat **confidence level indicator** pada tiap wilayah — badge yang membedakan skor "berdasarkan data lokal" (kuat, karena ada data historis ABJ/laporan di wilayah tsb) vs "estimasi model umum" (lemah, wilayah tanpa data historis memadai).
4. User dapat membuka bagian **prediksi tren 7–14 hari** — proyeksi risiko ke depan berdasarkan tren forecast cuaca.
5. Terdapat percabangan interaksi (decision point):
   - **Filter peta** → filter berdasarkan jenis penyakit (DBD/Malaria), rentang waktu, atau tingkat risiko → menampilkan peta tersaring sesuai kriteria.
   - **Pencarian wilayah** → user mengetik nama desa/kecamatan spesifik → sistem menampilkan skor risiko wilayah tersebut → muncul tombol **Subscribe Wilayah** untuk mengikuti update berkala wilayah itu.
6. Subscribe wilayah terhubung ke sistem kuota gamifikasi (lihat Bagian 8).

### 5.3 Laporan (`/laporan`)
Alur crowdsourcing warga:
1. Sistem menampilkan **form laporan**.
2. User mengunggah **foto genangan air liar**, langsung dari kamera ponsel atau galeri; sistem meminta izin akses GPS untuk **tag lokasi otomatis (geolocation)** — setelah disetujui, koordinat otomatis terisi.
3. User mengisi **deskripsi singkat** kondisi genangan.
4. User menekan tombol **"Kirim Laporan"**.
5. Sistem memberi **poin gamifikasi** kepada warga yang aktif melapor.
6. Halaman berpindah ke state **"Setelah Kirim Laporan"**, menampilkan:
   - **Status tindak lanjut laporan**: belum ditangani / sedang diproses / selesai fogging-abatisasi.
   - Poin yang didapat dari laporan tersebut.
   - Opsi **berbagi bukti kontribusi (twibbon)** ke Instagram/WhatsApp langsung dari halaman ini (juga dapat diakses ulang dari halaman notifikasi/riwayat aktivitas).

### 5.4 Statistik (`/statistik`)
- Diakses langsung oleh seluruh pengguna **tanpa login atau role khusus** (bukan dashboard eksklusif Dinkes/Peneliti — sudah digabung sesuai revisi di Bagian 2).
- Menampilkan **dashboard statistik wilayah**: grafik tren kasus, ABJ, dan laporan warga dalam satu tampilan.
- Fitur **perbandingan antarwilayah**: membandingkan skor risiko beberapa desa/kecamatan sekaligus.

### 5.5 Notifikasi (`/notifikasi`)
Berisi kartu-kartu notifikasi dengan jenis:
- **Kenaikan risiko wilayah**: alert otomatis saat skor risiko wilayah yang di-subscribe naik.
- **Cuaca ekstrem**: peringatan dini kondisi cuaca berpotensi memicu wabah.
- **Reward**: notifikasi saat pengguna mendapat akses reward baru (mis. bertambahnya kuota wilayah yang bisa di-subscribe).
- Halaman ini juga menjadi pusat manajemen **subscribe wilayah** pengguna (menambah/melihat wilayah yang diikuti).

### 5.6 Edukasi (`/edukasi`)
Tiga sub-bagian:
1. **Panduan pencegahan DBD/Malaria** — konten statis (3M Plus, cara mengenali gejala, dsb).
2. **Kalkulator risiko personal** — kuis singkat interaktif untuk mengecek seberapa rentan rumah/lingkungan pengguna; menghasilkan skor/hasil personal di akhir kuis (lihat halaman "EDUKASI PAGE - QUIZ").
3. **Artikel/berita kesehatan** — agregasi update terkini soal wabah di Indonesia dari sumber terpercaya; memiliki halaman detail artikel tersendiri ("EDUKASI PAGE - ARTIKEL").

### 5.7 Login Kader (dari sisi warga publik)
- Dipicu dari **ikon akun** di navbar landing page (bukan menu terpisah).
- Mengarahkan ke halaman login khusus kader kesehatan, terpisah dari alur warga publik.

---

## 6. Rincian Halaman & Fitur — Kader Kesehatan

### 6.1 Login Kader (`/kader/login`)
- Kader memasukkan kredensial login khusus kader.
- Setelah verifikasi berhasil → masuk ke Dashboard utama.

### 6.2 Dashboard Kader (`/kader/dashboard`)
- Menampilkan **ringkasan wilayah binaan** kader dan **grafik tren singkat ABJ**.
- Berisi 5 submenu utama yang harus mudah dijangkau (idealnya via sidebar/tab): **Kelola Data ABJ, Riwayat & Tren, Laporan, Notifikasi, Pengaturan**.
- Setiap submenu dirancang agar detail dapat diakses cepat (minim klik) dari dashboard.

### 6.3 Kelola Data ABJ (`/kader/abj`)
Alur input data lapangan pengganti kertas:
1. Kader memilih **wilayah kerja RT/RW** yang akan diperiksa.
2. Kader mengisi **form digital** berisi jumlah rumah diperiksa dan jumlah rumah positif jentik.
3. Kader menekan tombol **"Simpan Riwayat"**.

### 6.4 Riwayat & Tren (`/kader/riwayat`)
- Kader melihat **riwayat input per wilayah**.
- **Grafik visualisasi tren mingguan (Chart.js)** yang otomatis diperbarui setiap ada input baru.
- Fitur **perbandingan antarwilayah** untuk melihat performa ABJ lintas RT/RW yang menjadi tanggung jawab kader.

### 6.5 Laporan (`/kader/laporan`)
- Kader memilih **rentang data** yang ingin direkap.
- Sistem menyediakan tombol **export laporan** dalam format **PDF/Excel** untuk keperluan pelaporan resmi ke Puskesmas/Dinkes.
- Catatan: tampilan ini memiliki dua varian desain wireframe (LAPORAN PAGE & LAPORAN PAGE-1) — pastikan versi final dikonfirmasi dengan Anggota 1 sebelum implementasi akhir.

### 6.6 Notifikasi (`/kader/notifikasi`)
- Sistem mengirimkan **reminder rutin** ke kader sebagai pengingat jadwal pemeriksaan jentik berkala.
- Kader dapat **menandai jadwal pemeriksaan sebagai selesai** setelah menyelesaikan kunjungan lapangan.

### 6.7 Pengaturan (`/kader/pengaturan`)
- Halaman pengaturan akun kader (belum dirinci lebih lanjut di wireframe — diasumsikan berisi profil, ubah kata sandi, preferensi notifikasi).

---

## 7. Alur Pengguna Lengkap (User Flow — ringkasan naratif)

### Alur 1: Warga Publik
`Membuka halaman web` → decision: *akses peta risiko / statistik / laporan / notifikasi / edukasi / onboarding?* → salah satu dari 5 cabang fitur di atas (Bagian 5.2–5.6) → khusus cabang **Peta Risiko**, ada sub-decision **filter peta vs pencarian wilayah**, dan alur **Laporan** berujung pada opsi share twibbon yang juga terhubung balik ke alur **Notifikasi** (notifikasi reward).

### Alur 2: Kader Kesehatan
`Membuka halaman web` → `Kader mengklik Portal Kader` → `Kader memasukkan kredensial login khusus kader` → `Verifikasi berhasil → masuk Dashboard utama` → decision: *akses Kelola Data ABJ / Riwayat & Tren / Laporan / Notifikasi / Pengaturan?* → salah satu dari 5 cabang fitur di atas (Bagian 6.3–6.7).

**Catatan penting dari user flow:** di kedua alur, poin desain navigasi ditegaskan berkali-kali sebagai catatan tim (sticky notes pada diagram) — ini menunjukkan prioritas UX:
- Navigasi harus "sesimpel dan seringkas mungkin" — fungsi utama terjangkau dari navbar.
- Onboarding hanya tampil otomatis 1x di kunjungan pertama, tapi bisa dipanggil ulang manual.
- Dashboard kader harus menampilkan ringkasan wilayah binaan & grafik tren singkat ABJ, dengan pengingat pemeriksaan, notifikasi, dan pengaturan cepat dijangkau (minim klik menuju detail).

---

## 8. Sistem Gamifikasi & Reward

- **Poin kontribusi**: warga mendapat poin setiap kali berhasil mengirim laporan genangan air.
- **Kuota Subscribe Wilayah**: secara default, pengguna hanya bisa subscribe **1 wilayah**. Semakin tinggi poin warga, kuota bertambah (mis. bisa memantau wilayah rumah, sekolah anak, dan tempat kerja sekaligus).
- **Notifikasi reward**: sistem mengabari pengguna saat kuota subscribe bertambah sebagai insentif kontribusi.
- **Share bukti kontribusi**: twibbon/postingan dapat dibagikan ke Instagram Story/WhatsApp Status langsung dari website (dari halaman laporan setelah kirim, atau dari notifikasi/riwayat aktivitas).

---

## 9. Sistem Skoring Risiko (Logika Inti)

Skor risiko per wilayah dihitung dari kombinasi:
1. **Data cuaca real-time** (suhu, kelembapan, curah hujan) via API cuaca eksternal.
2. **Data historis lokal** (ABJ dari kader + akumulasi laporan warga) bila tersedia untuk wilayah tsb.
3. Bila wilayah **tidak** punya data historis memadai → skor dihitung dari **estimasi model umum** (berbasis data cuaca saja), dan ditandai badge "estimasi model umum" (confidence rendah). Bila wilayah **punya** data historis → ditandai "berdasarkan data lokal" (confidence kuat).
4. **Prediksi tren 7–14 hari** dihasilkan dari proyeksi sederhana atas data forecast cuaca ke depan.

Output skor ditampilkan dalam 3 tingkat: **Rendah (hijau) / Sedang (kuning) / Tinggi (merah)**, dan digunakan konsisten di: Peta Risiko, Statistik, dan pemicu Notifikasi kenaikan risiko.

---

## 10. Desain UI/UX — Observasi dari Wireframe/Mockup

Berdasarkan mockup yang telah dibuat (USER_PAGE & KADER_PAGE), berikut pola desain yang harus dipertahankan konsistensinya saat coding:

- **Warga Publik**: layout halaman publik menggunakan struktur vertikal panjang (long-scroll single page per fitur) dengan navbar sticky di atas, hero/header section, lalu blok-blok konten fitur tersusun ke bawah (peta, filter, form, dsb). Tiap halaman fitur (Peta Risiko, Laporan, Statistik, Edukasi, Notifikasi) merupakan halaman penuh tersendiri yang diakses dari navbar/landing page.
- **Kode warna risiko**: konsisten hijau/kuning/merah (rendah/sedang/tinggi) di semua tampilan peta dan statistik — jangan gunakan skema warna lain untuk representasi level risiko agar tidak membingungkan pengguna.
- **Kader Kesehatan**: layout dashboard menggunakan pola **sidebar/panel navigasi + area konten kartu (card-based)** khas dashboard admin — beda dari layout warga publik yang long-scroll. Ini mencerminkan kebutuhan kader untuk berpindah cepat antar submenu (Kelola ABJ, Riwayat & Tren, Laporan, Notifikasi, Pengaturan) tanpa scroll panjang.
- **Form input** (Laporan warga, Kelola Data ABJ kader) dirancang ringkas — field minimal, dengan auto-fill untuk data yang bisa diotomatisasi (geolocation, koordinat).
- **Visualisasi data** menggunakan **Chart.js** untuk grafik tren (baik di Statistik warga maupun Riwayat & Tren kader) — pastikan komponen chart reusable antara kedua konteks ini.
- **State kosong/sukses**: halaman Laporan memiliki state khusus pasca-submit ("Setelah Kirim Laporan") yang harus diimplementasikan sebagai state/halaman terpisah, bukan sekadar toast notification, karena menampilkan status tindak lanjut & opsi share.
- **Edukasi** memiliki 3 tampilan berbeda yang harus diimplementasikan sebagai rute/komponen terpisah: daftar edukasi, detail artikel, dan kuis kalkulator risiko (dengan alur pertanyaan bertahap dan hasil akhir).

> Catatan: file asli desain (Figma/gambar mockup) tersedia terpisah sebagai referensi visual detail (warna presisi, tipografi, spacing) — dokumen ini merangkum **struktur & perilaku** komponen, bukan menggantikan spesifikasi visual piksel-presisi dari file desain.

---

## 11. Kebutuhan Data / Entitas Utama (untuk perancangan database)

| Entitas | Atribut Kunci | Digunakan di |
| --- | --- | --- |
| **Wilayah** (desa/kecamatan) | nama, kode wilayah, koordinat/boundary GIS, skor risiko terkini, confidence level, riwayat skor | Peta Risiko, Statistik, Subscribe |
| **DataCuaca** | wilayah, suhu, kelembapan, curah hujan, timestamp, sumber API | Skoring risiko, prediksi tren |
| **DataABJ** | kader_id, wilayah/RT-RW, jumlah rumah diperiksa, jumlah rumah positif jentik, tanggal input | Kelola ABJ, Riwayat & Tren, Export Laporan |
| **LaporanWarga** | user_id (anonim/sesi), foto, koordinat, deskripsi, status (belum ditangani/diproses/selesai), poin diberikan, timestamp | Laporan, Statistik |
| **User (Warga)** | identitas minimal/sesi, poin, kuota subscribe, daftar wilayah subscribe | Gamifikasi, Notifikasi |
| **Kader** | kredensial login, wilayah kerja (RT/RW binaan), riwayat input | Login Kader, Dashboard Kader |
| **Notifikasi** | tipe (kenaikan risiko/cuaca ekstrem/reward/reminder), target (user/kader), status baca | Notifikasi warga, Notifikasi kader |
| **KontenEdukasi** | tipe (artikel/panduan/kuis), judul, isi, sumber | Edukasi |

---

## 12. Kebutuhan Non-Fungsional

- **Kebijakan Privasi & Keamanan Data**: wajib ada halaman Privacy Policy; implementasi keamanan data dasar di backend (khususnya karena ada data lokasi & foto pengguna).
- **Mode offline-first**: khusus untuk input data kader di daerah dengan sinyal lemah — data tersimpan lokal dan sinkron otomatis saat online kembali.
- **PWA (Progressive Web App)**: agar dapat diakses seperti aplikasi native tanpa instalasi dari app store.
- **Bahasa daerah/lokal**: opsional, untuk aksesibilitas di daerah tertentu (fitur nice-to-have, bukan prioritas Tahap 1).
- **Responsif**: navigasi dan seluruh tampilan harus ramah pengguna di berbagai ukuran layar (mobile-first, mengingat target pengguna warga & kader lapangan lebih banyak mengakses via ponsel).
- **Kualitas teknis & struktur kode**: dinilai dalam rubrik — pastikan struktur proyek rapi, terpisah jelas antara frontend/backend, dan bebas error saat testing fungsionalitas akhir.

---

## 13. Tech Stack (berdasarkan rancangan tim)

- Frontend: vue
- Peta: library Web-GIS (mis. Leaflet/Mapbox — riset pemilihan final ada di tanggung jawab Anggota 2)
- Visualisasi grafik: Chart.js
- Data cuaca: integrasi API cuaca eksternal (suhu, kelembapan, curah hujan)
- Backend & Database:laravel
- Deployment: Vercel/Netlify (atau AWS sebagai alternatif)

---

## 14. Pembagian Tanggung Jawab Fitur (untuk konteks siapa mengerjakan apa)

| Peran | Fitur yang menjadi tanggung jawab teknis |
| --- | --- |
| Anggota 1 (UI/UX & Frontend) | Wireframe & UI aktual (Figma), Web-GIS Map UI, Dashboard UI, Form Pelaporan UI, navigasi responsif |
| Anggota 2 (Backend, GIS, Data) | Integrasi API cuaca, database ABJ & laporan warga, kalkulasi heatmap/skor risiko, keamanan data dasar, kebijakan privasi backend, deployment |
| Ketua Tim | Proposal, administrasi, narasi urgensi masalah & solusi, koordinasi timeline, memastikan kesesuaian website dengan proposal |

---

## 15. Catatan Penggunaan Dokumen Ini

Saat menggunakan dokumen ini sebagai konteks prompt coding:
1. Selalu rujuk **Bagian 2 (Prinsip Desain & Keputusan Penting)** sebagai batasan yang tidak boleh dilanggar.
2. Gunakan **Bagian 5 & 6** sebagai spesifikasi fungsional per halaman saat meminta pembuatan komponen/halaman tertentu.
3. Gunakan **Bagian 11** sebagai acuan skema data saat membangun backend/database.
4. Jika ada penyesuaian desain baru dari file Figma/mockup yang belum tercakup di sini, prioritaskan mockup visual untuk detail piksel, dan dokumen ini untuk detail perilaku/alur/logika.
