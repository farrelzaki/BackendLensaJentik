<?php
namespace App\Exports;

use App\Models\AbjLaporan;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AbjExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        protected string $wilayahKode,
        protected ?string $dariTanggal = null,
        protected ?string $sampaiTanggal = null
    ) {}

    public function collection()
    {
        $query = AbjLaporan::where('wilayah_kode', $this->wilayahKode)
            ->with(['user:id,nama', 'wilayah:kode,nama'])
            ->orderBy('tanggal_pemeriksaan');

        if ($this->dariTanggal) {
            $query->where('tanggal_pemeriksaan', '>=', $this->dariTanggal);
        }

        if ($this->sampaiTanggal) {
            $query->where('tanggal_pemeriksaan', '<=', $this->sampaiTanggal);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Tanggal Pemeriksaan',
            'Wilayah',
            'Nama Kader',
            'Rumah Diperiksa',
            'Rumah Positif Jentik',
            'ABJ (%)',
            'Catatan',
        ];
    }

    public function map($row): array
    {
        return [
            $row->tanggal_pemeriksaan->format('d-m-Y'),
            $row->wilayah->nama ?? '-',
            $row->user->nama ?? '-',
            $row->jumlah_rumah_diperiksa,
            $row->jumlah_rumah_positif,
            $row->abj_persen,
            $row->catatan ?? '-',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}