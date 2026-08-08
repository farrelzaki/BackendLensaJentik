<?php

namespace App\Exports;

use App\Models\SkorRisiko;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet "Skor Risiko" — data skor risiko aktual (is_prediksi = false),
 * lengkap dengan jenis penyakit, level, dan confidence level.
 */
class RisetSkorRisikoSheet implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        protected array $wilayahKodes = [],
        protected ?string $dari = null,
        protected ?string $sampai = null
    ) {}

    public function collection()
    {
        $query = SkorRisiko::with('wilayah:kode,nama')
            ->where('is_prediksi', false)
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
            'Jenis Penyakit',
            'Tanggal',
            'Skor',
            'Level Risiko',
            'Confidence',
        ];
    }

    public function map($row): array
    {
        return [
            $row->wilayah_kode,
            $row->wilayah->nama ?? '-',
            $row->jenis_penyakit ?? '-',
            $row->tanggal->format('d-m-Y'),
            $row->skor,
            $row->level_risiko ?? '-',
            $row->confidence_level ?? '-',
        ];
    }

    public function title(): string
    {
        return 'Skor Risiko';
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
