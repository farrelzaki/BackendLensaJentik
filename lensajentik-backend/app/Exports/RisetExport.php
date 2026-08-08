<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Dataset riset multi-sheet untuk halaman Statistik.
 *
 * Sheet pertama selalu "Metadata" (informasi sumber, filter, dan metodologi).
 * Sheet data tambahan mengikuti parameter jenis_data[]:
 *   skor_risiko, data_abj, laporan_warga, data_cuaca.
 */
class RisetExport implements WithMultipleSheets
{
    public function __construct(
        protected ?string $wilayahKode = null,
        protected ?string $dari = null,
        protected ?string $sampai = null,
        protected array $jenisData = []
    ) {}

    public function sheets(): array
    {
        $wilayahKodes = $this->wilayahKode ? $this->resolveKecamatanCodes($this->wilayahKode) : [];

        $sheets = [new RisetMetadataSheet($this->wilayahKode, $this->dari, $this->sampai, $this->jenisData)];

        if (in_array('skor_risiko', $this->jenisData)) {
            $sheets[] = new RisetSkorRisikoSheet($wilayahKodes, $this->dari, $this->sampai);
        }

        if (in_array('data_abj', $this->jenisData)) {
            $sheets[] = new RisetAbjSheet($wilayahKodes, $this->dari, $this->sampai);
        }

        if (in_array('laporan_warga', $this->jenisData)) {
            $sheets[] = new RisetLaporanWargaSheet($wilayahKodes, $this->dari, $this->sampai);
        }

        if (in_array('data_cuaca', $this->jenisData)) {
            $sheets[] = new RisetCuacaSheet($wilayahKodes, $this->dari, $this->sampai);
        }

        return $sheets;
    }

    /**
     * Ubah kode wilayah menjadi daftar kode kecamatan yang tercakup.
     *
     * Data operasional (skor, ABJ, cuaca, laporan) disimpan di tingkat
     * kecamatan. Jika user memilih kabupaten/provinsi, semua kecamatan di
     * bawahnya ikut disertakan; jika sudah kecamatan, hanya dirinya sendiri.
     */
    protected function resolveKecamatanCodes(string $wilayahKode): array
    {
        $wilayah = \App\Models\Wilayah::find($wilayahKode);

        if (!$wilayah) {
            return [$wilayahKode];
        }

        $codes = $wilayah->getAllKecamatanCodes();

        return empty($codes) ? [$wilayahKode] : $codes;
    }
}
