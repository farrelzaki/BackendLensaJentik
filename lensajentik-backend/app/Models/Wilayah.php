<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Wilayah extends Model
{
    protected $table = 'wilayah';
    protected $primaryKey = 'kode';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['kode', 'nama', 'tingkat', 'parent_kode', 'latitude', 'longitude', 'elevasi'];

    public function parent()
    {
        return $this->belongsTo(Wilayah::class, 'parent_kode', 'kode');
    }

    public function children()
    {
        return $this->hasMany(Wilayah::class, 'parent_kode', 'kode');
    }

    public function dataCuaca()
    {
        return $this->hasMany(DataCuaca::class, 'wilayah_kode', 'kode');
    }

    public function skorRisiko()
    {
        return $this->hasMany(SkorRisiko::class, 'wilayah_kode', 'kode');
    }

    public function abjLaporan()
    {
        return $this->hasMany(AbjLaporan::class, 'wilayah_kode', 'kode');
    }

    public function laporanWarga()
    {
        return $this->hasMany(LaporanWarga::class, 'wilayah_kode', 'kode');
    }

    /**
     * Ambil SEMUA kode kecamatan di bawah wilayah ini secara rekursif.
     *
     * - Jika tingkat = 'kecamatan': return [kode sendiri]
     * - Jika tingkat = 'kabupaten': return semua kecamatan dengan parent_kode = kode ini
     * - Jika tingkat = 'provinsi': return semua kecamatan di bawah semua kabupaten di bawah provinsi ini
     * - Jika tingkat = 'desa': return parent kecamatan dari desa ini
     *
     * @return array<string>
     */
    public function getAllKecamatanCodes(): array
    {
        if ($this->tingkat === 'kecamatan') {
            return [$this->kode];
        }

        if ($this->tingkat === 'desa') {
            // Desa → parent kecamatan
            $kec = Wilayah::where('kode', $this->parent_kode)
                ->where('tingkat', 'kecamatan')
                ->first();
            return $kec ? [$kec->kode] : [];
        }

        if ($this->tingkat === 'kabupaten') {
            // Kabupaten → semua kecamatan langsung
            return Wilayah::where('parent_kode', $this->kode)
                ->where('tingkat', 'kecamatan')
                ->pluck('kode')
                ->toArray();
        }

        if ($this->tingkat === 'provinsi') {
            // Provinsi → semua kabupaten → semua kecamatan
            $kabupatenCodes = Wilayah::where('parent_kode', $this->kode)
                ->where('tingkat', 'kabupaten')
                ->pluck('kode')
                ->toArray();

            if (empty($kabupatenCodes)) {
                return [];
            }

            return Wilayah::whereIn('parent_kode', $kabupatenCodes)
                ->where('tingkat', 'kecamatan')
                ->pluck('kode')
                ->toArray();
        }

        return [];
    }

    /**
     * Apakah wilayah ini level administratif (kabupaten/provinsi) yang perlu agregasi?
     */
    public function isAdministrativeLevel(): bool
    {
        return in_array($this->tingkat, ['kabupaten', 'provinsi']);
    }
}
