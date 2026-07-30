# Prompt Eksekusi: Implementasi Skor Risiko DBD Berbasis Cuaca (Open-Meteo)

## Konteks

Aplikasi Laravel untuk pemetaan risiko DBD (Demam Berdarah Dengue) per wilayah/kecamatan. Saat ini sistem butuh model skor risiko yang bisa jalan **tanpa data lapangan** (jentik/ABJ), murni dari data cuaca, sebagai fallback dengan label `confidence_level = "lemah"`. Data lapangan (kalau ada) akan menaikkan confidence level di iterasi berikutnya — di luar scope prompt ini.

Sumber data: **Open-Meteo API** (sudah terintegrasi di project), field: `temperature_2m_mean`, `precipitation_sum`, `relative_humidity_2m_mean`, plus **Open-Meteo Elevation API**.

## Tujuan

Buat job `HitungSkorRisikoJob` (Laravel) yang menghitung skor risiko DBD per wilayah dari data cuaca historis + forecast, dengan formula berikut.

## Spesifikasi Formula

```
skor_cuaca = (0.4 × f_suhu(T)) + (0.4 × f_hujan(R_7hari)) + (0.2 × f_lembap(RH))
```

**f_suhu(T)** — bell curve, puncak 27°C:
```
f_suhu = max(0, 100 - 4 × (T - 27)^2)
```

**f_hujan(R_7hari)** — akumulasi curah hujan 7 hari terakhir (mm), piecewise:
- `R_7hari < 10mm` → nilai rendah (terlalu kering)
- `R_7hari` antara 20–80mm → nilai tinggi (genangan optimal)
- `R_7hari > 150mm` → turun lagi (risiko tersapu banjir)
- gunakan interpolasi linear piecewise antar titik-titik ini (tidak perlu kurva kompleks)

**f_lembap(RH)** — linear naik:
```
f_lembap = clamp((RH - 40) / 0.6, 0, 100)
```

**Elevasi**: jika elevasi wilayah > 1000 mdpl, kurangi skor akhir (mis. kalikan faktor penalti, atau cap maksimum skor) karena *Aedes aegypti* jarang bertahan di dataran tinggi dingin.

## Tugas Implementasi

1. **Fetch data Open-Meteo** dalam satu request (gabungkan past + forecast):
   ```
   GET https://api.open-meteo.com/v1/forecast
       ?latitude=..&longitude=..
       &daily=temperature_2m_mean,precipitation_sum,relative_humidity_2m_mean
       &past_days=14
       &forecast_days=16
   ```
2. **Fetch elevasi** sekali per wilayah (bukan per request) saat seeding:
   ```
   GET https://api.open-meteo.com/v1/elevation?latitude=..&longitude=..
   ```
   Simpan hasilnya ke kolom `elevasi` di tabel `wilayah`.
3. **Implementasikan 3 fungsi skor** (`f_suhu`, `f_hujan`, `f_lembap`) sebagai method terpisah agar mudah di-unit-test.
4. **Hitung akumulasi curah hujan 7 hari berjalan** dari data harian (rolling sum), bukan cuma nilai harian tunggal.
5. **Terapkan penalti elevasi** setelah skor cuaca dihitung.
6. **Jalankan formula ke data forecast** juga (7–14 hari ke depan) untuk mengisi tabel `prediksi_risiko`.
7. **Set `confidence_level = "lemah"` otomatis** untuk semua wilayah yang belum punya `data_abj` (data lapangan/jentik). Jangan override manual.
8. Tulis sebagai Laravel Job (`app/Jobs/HitungSkorRisikoJob.php`) yang bisa dipanggil per wilayah atau untuk semua wilayah via scheduler.

## Kriteria Penerimaan

- [ ] Skor akhir selalu dalam rentang 0–100.
- [ ] Wilayah dengan elevasi > 1000 mdpl menunjukkan skor lebih rendah dibanding wilayah dataran rendah dengan cuaca identik.
- [ ] Satu API call Open-Meteo menghasilkan data historis (14 hari) DAN forecast (16 hari) sekaligus — tidak ada double call.
- [ ] Elevation API hanya dipanggil sekali per wilayah (saat seeding), bukan tiap job berjalan.
- [ ] `confidence_level` konsisten `"lemah"` untuk wilayah tanpa `data_abj`, tanpa perlu campur tangan manual.
- [ ] Ada unit test untuk `f_suhu`, `f_hujan`, `f_lembap` dengan beberapa nilai batas (mis. T=27, T=18, T=34; R=5, R=50, R=200).
- [ ] Tabel `prediksi_risiko` terisi otomatis dari hasil forecast.

## Di Luar Scope (jangan dikerjakan sekarang)

- Integrasi BMKG (pembanding data cuaca resmi) — prioritas rendah, effort tinggi.
- Integrasi BPS WebAPI (kepadatan penduduk) — butuh API key & effort integrasi lebih dari semalam.
- Data historis kasus DBD Kemenkes — biasanya format Excel/manual, bukan REST API.

## Output yang Diharapkan

Kode PHP/Laravel lengkap untuk `HitungSkorRisikoJob`, termasuk method-method skor terpisah, logic fetch Open-Meteo (forecast + past_days digabung), logic fetch & simpan elevasi saat seeding, penalti elevasi, dan penulisan ke tabel `prediksi_risiko`.
