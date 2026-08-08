<?php

namespace App\Exports;

use App\Models\Wilayah;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet "Metadata" — ringkasan sumber, filter yang dipakai, dan metodologi
 * singkat dari dataset riset yang diunduh.
 */
class RisetMetadataSheet implements FromArray, WithTitle, WithStyles
{
    public function __construct(
        protected ?string $wilayahKode = null,
        protected ?string $dari = null,
        protected ?string $sampai = null,
        protected array $jenisData = []
    ) {}

    public function array(): array
    {
        $wilayah = $this->wilayahKode ? Wilayah::find($this->wilayahKode) : null;
        $namaWilayah = $wilayah ? "{$wilayah->nama} ({$wilayah->kode})" : 'Semua Indonesia';

        if ($this->dari && $this->sampai) {
            $rentang = "{$this->dari} s/d {$this->sampai}";
        } elseif ($this->dari) {
            $rentang = "Dari {$this->dari}";
        } elseif ($this->sampai) {
            $rentang = "Sampai {$this->sampai}";
        } else {
            $rentang = 'Semua data (tanpa filter tanggal)';
        }

        $label = [
            'skor_risiko'   => 'Skor Risiko',
            'data_abj'      => 'Data ABJ',
            'laporan_warga' => 'Laporan Warga',
            'data_cuaca'    => 'Data Cuaca',
        ];
        $jenisLabel = array_map(fn ($j) => $label[$j] ?? $j, $this->jenisData);

        return [
            ['Nama Sumber', 'LensaJentik — TIC 9.0'],
            ['Tanggal Generate', now()->format('d-m-Y H:i')],
            ['Wilayah', $namaWilayah],
            ['Rentang Tanggal', $rentang],
            ['Jenis Data', implode(', ', $jenisLabel)],
            ['Catatan Metodologi', 'Dataset dihasilkan otomatis oleh sistem pemantauan DBD/malaria LensaJentik. ' .
                'Skor risiko berkisar 0–100 dengan level rendah (<40), sedang (40–70), dan tinggi (≥70). ' .
                'Confidence level "kuat" menandakan skor berbasis data lapangan (ABJ), sedangkan "lemah" berarti estimasi berbasis cuaca.'],
        ];
    }

    public function title(): string
    {
        return 'Metadata';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
