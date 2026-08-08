<?php

namespace App\Exports;

use App\Models\AbjLaporan;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet "Data ABJ" — hasil pemeriksaan jentik oleh kader, mengikuti pola
 * kolom pada AbjExport.
 */
class RisetAbjSheet implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        protected array $wilayahKodes = [],
        protected ?string $dari = null,
        protected ?string $sampai = null
    ) {}

    public function collection()
    {
        $query = AbjLaporan::with(['user:id,nama', 'wilayah:kode,nama'])
            ->orderBy('tanggal_pemeriksaan');

        if (!empty($this->wilayahKodes)) {
            $query->whereIn('wilayah_kode', $this->wilayahKodes);
        }

        if ($this->dari) {
            $query->where('tanggal_pemeriksaan', '>=', $this->dari);
        }

        if ($this->sampai) {
            $query->where('tanggal_pemeriksaan', '<=', $this->sampai);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Kode Wilayah',
            'Nama Wilayah',
            'Tanggal Pemeriksaan',
            'Rumah Diperiksa',
            'Rumah Positif Jentik',
            'ABJ (%)',
            'Nama Kader',
            'Catatan',
        ];
    }

    public function map($row): array
    {
        return [
            $row->wilayah_kode,
            $row->wilayah->nama ?? '-',
            $row->tanggal_pemeriksaan->format('d-m-Y'),
            $row->jumlah_rumah_diperiksa,
            $row->jumlah_rumah_positif,
            $row->abj_persen,
            $row->user->nama ?? '-',
            $row->catatan ?? '-',
        ];
    }

    public function title(): string
    {
        return 'Data ABJ';
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
