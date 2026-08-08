<?php
namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GapAbjExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        protected ?string $dariTanggal = null,
        protected ?string $sampaiTanggal = null,
        protected ?string $parentKode = null
    ) {}

    public function collection()
    {
        $query = DB::table('abj_laporan')
            ->join('wilayah', 'wilayah.kode', '=', 'abj_laporan.wilayah_kode')
            ->where('wilayah.tingkat', 'kecamatan')
            ->select(
                'wilayah.kode',
                'wilayah.nama',
                'wilayah.parent_kode',
                DB::raw('ROUND(AVG(abj_laporan.abj_persen)::numeric, 2) as rata_abj')
            )
            ->groupBy('wilayah.kode', 'wilayah.nama', 'wilayah.parent_kode')
            ->havingRaw('AVG(abj_laporan.abj_persen) < 95')
            ->orderBy('rata_abj');

        if ($this->dariTanggal) {
            $query->where('abj_laporan.tanggal_pemeriksaan', '>=', $this->dariTanggal);
        }

        if ($this->sampaiTanggal) {
            $query->where('abj_laporan.tanggal_pemeriksaan', '<=', $this->sampaiTanggal);
        }

        if ($this->parentKode) {
            $query->where('wilayah.parent_kode', $this->parentKode);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Nama Kecamatan',
            'Rata-rata ABJ (%)',
            'Gap dari Target 95%',
            'Status',
        ];
    }

    public function map($row): array
    {
        $rata = (float) $row->rata_abj;
        $gap = round(95 - $rata, 2);

        // Semakin besar gap, semakin kritis kondisinya.
        $status = $gap >= 10 ? 'Kritis' : ($gap >= 5 ? 'Waspada' : 'Perlu Perhatian');

        return [
            $row->nama,
            $rata,
            $gap,
            $status,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
