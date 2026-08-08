<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1E2B5B; }
        .header { text-align: center; border-bottom: 3px solid #4E63DA; padding-bottom: 12px; margin-bottom: 20px; }
        .header h1 { font-size: 20px; margin: 0 0 4px; color: #1E2B5B; }
        .header p { margin: 2px 0; color: #6B7280; font-size: 11px; }
        h2 { font-size: 14px; color: #4E63DA; margin: 20px 0 8px; }
        .narasi { background: #F3F5FF; border-left: 4px solid #4E63DA; padding: 14px 16px; line-height: 1.7; text-align: justify; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #D1D5DB; padding: 8px 10px; text-align: left; font-size: 11px; }
        th { background: #EEF2FF; color: #1E2B5B; }
        .label { color: #6B7280; }
        .value { font-weight: bold; color: #1E2B5B; }
        .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #E5E7EB; font-size: 10px; color: #9CA3AF; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>LensaJentik — Laporan Ringkas</h1>
        <p>{{ $wilayah->nama }} ({{ $wilayah->kode }}) · Jenis: {{ $jenis }} · Periode: {{ $periode }}</p>
    </div>

    <h2>Narasi Otomatis</h2>
    <div class="narasi">{{ $narasi }}</div>

    <h2>Ringkasan Indikator</h2>
    <table>
        <tr>
            <th>Indikator</th>
            <th>Nilai</th>
        </tr>
        <tr>
            <td class="label">Skor Risiko</td>
            <td class="value">{{ isset($ringkasan['skor']) && $ringkasan['skor'] !== null ? $ringkasan['skor'] : '—' }} / 100</td>
        </tr>
        <tr>
            <td class="label">Level Risiko</td>
            <td class="value">{{ isset($ringkasan['level']) && $ringkasan['level'] ? ucfirst($ringkasan['level']) : '—' }}</td>
        </tr>
        <tr>
            <td class="label">Skor 7 Hari Lalu</td>
            <td class="value">{{ isset($ringkasan['skor_7_hari_lalu']) && $ringkasan['skor_7_hari_lalu'] !== null ? $ringkasan['skor_7_hari_lalu'] : '—' }} / 100</td>
        </tr>
        <tr>
            <td class="label">Rata-rata ABJ</td>
            <td class="value">{{ isset($ringkasan['abj']) && $ringkasan['abj'] !== null ? $ringkasan['abj'] : '—' }}%</td>
        </tr>
        <tr>
            <td class="label">Curah Hujan Tertinggi</td>
            <td class="value">{{ isset($ringkasan['curah_hujan_tertinggi']) && $ringkasan['curah_hujan_tertinggi'] !== null ? $ringkasan['curah_hujan_tertinggi'] : '—' }} mm</td>
        </tr>
        <tr>
            <td class="label">Total Laporan Warga</td>
            <td class="value">{{ $ringkasan['total_laporan'] ?? 0 }}</td>
        </tr>
    </table>

    <div class="footer">
        Dihasilkan oleh LensaJentik — TIC 9.0 · {{ now()->format('d-m-Y H:i') }}
    </div>
</body>
</html>
