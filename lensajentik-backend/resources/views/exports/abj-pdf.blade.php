<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        h2 { text-align: center; margin-bottom: 4px; }
        p.subtitle { text-align: center; color: #666; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f0f0f0; }
        .abj-rendah { color: #c62828; font-weight: bold; }
    </style>
</head>
<body>
    <h2>Laporan Angka Bebas Jentik (ABJ)</h2>
    <p class="subtitle">Wilayah: {{ $wilayah->nama }} | Periode: {{ $periode }}</p>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Kader</th>
                <th>Rumah Diperiksa</th>
                <th>Rumah Positif</th>
                <th>ABJ (%)</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data as $row)
            <tr>
                <td>{{ $row->tanggal_pemeriksaan->format('d-m-Y') }}</td>
                <td>{{ $row->kader->name ?? '-' }}</td>
                <td>{{ $row->jumlah_rumah_diperiksa }}</td>
                <td>{{ $row->jumlah_rumah_positif_jentik }}</td>
                <td class="{{ $row->abj_persen < 95 ? 'abj-rendah' : '' }}">{{ $row->abj_persen }}%</td>
                <td>{{ $row->catatan ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin-top: 30px; font-size: 10px; color: #888;">
        Rata-rata ABJ periode ini: {{ number_format($rataRata, 2) }}% —
        Standar Kemenkes: ABJ ≥ 95% dianggap aman.
        <br>Dokumen digenerate otomatis oleh sistem LensaJentik pada {{ now()->format('d-m-Y H:i') }}.
    </p>
</body>
</html>