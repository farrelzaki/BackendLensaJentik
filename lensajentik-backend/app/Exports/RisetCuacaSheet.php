<?php

namespace App\Exports;

use App\Models\DataCuaca;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet "Data Cuaca" — data cuaca historis & forecast per wilayah.
 */
class RisetCuacaSheet implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        protected array $wilayahKodes = [],
        protected ?string $dari = null,
        protected ?string $sampai = null
    ) {}

    public function collection()
    {
        $query = DataCuaca::with('wilayah:kode,nama')
            ->orderBy('tanggal')
            ->orderBy('wilayah_kode');

        if (!empty($this->wilayahKodes)) {
            $query->whereIn('wilayah_kode', $this->wilayahKodes);
        }

        if ($this->dari) {
            $query->where('tanggal', '>=', $this->dari);
        }

        if ($this->sampai) {
            $query->where('tanggal', '<=', $this->sampai);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Kode Wilayah',
            'Nama Wilayah',
            'Tanggal',
            'Suhu Rata-rata (°C)',
            'Kelembapan Rata-rata (%)',
            'Curah Hujan (mm)',
            'Tipe',
            'Sumber API',
        ];
    }

    public function map($row): array
    {
        return [
            $row->wilayah_kode,
            $row->wilayah->nama ?? '-',
            $row->tanggal->format('d-m-Y'),
            $row->suhu_avg ?? '-',
            $row->kelembapan_avg ?? '-',
            $row->curah_hujan ?? '-',
            $row->is_forecast ? 'Forecast' : 'Historis',
            $row->sumber_api ?? '-',
        ];
    }

    public function title(): string
    {
        return 'Data Cuaca';
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
