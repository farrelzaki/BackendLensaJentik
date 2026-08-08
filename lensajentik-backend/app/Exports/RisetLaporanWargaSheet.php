<?php

namespace App\Exports;

use App\Models\LaporanWarga;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet "Laporan Warga" — laporan masyarakat tentang potensi DBD/malaria.
 */
class RisetLaporanWargaSheet implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        protected array $wilayahKodes = [],
        protected ?string $dari = null,
        protected ?string $sampai = null
    ) {}

    public function collection()
    {
        $query = LaporanWarga::with('wilayah:kode,nama')
            ->orderBy('created_at');

        if (!empty($this->wilayahKodes)) {
            $query->whereIn('wilayah_kode', $this->wilayahKodes);
        }

        if ($this->dari) {
            $query->where('created_at', '>=', $this->dari);
        }

        if ($this->sampai) {
            $query->where('created_at', '<=', $this->sampai . ' 23:59:59');
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Kode Wilayah',
            'Nama Wilayah',
            'Tanggal',
            'Nama Pelapor',
            'Status',
            'Alamat',
            'Deskripsi',
            'Jumlah Verifikasi',
        ];
    }

    public function map($row): array
    {
        return [
            $row->wilayah_kode,
            $row->wilayah->nama ?? '-',
            $row->created_at->format('d-m-Y H:i'),
            $row->nama_pelapor ?? '-',
            $row->status ?? '-',
            $row->alamat_text ?? '-',
            $row->deskripsi ?? '-',
            $row->jumlah_verifikasi ?? 0,
        ];
    }

    public function title(): string
    {
        return 'Laporan Warga';
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
