<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wilayah extends Model
{
    protected $table = 'wilayah';
    protected $primaryKey = 'kode';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['kode', 'nama', 'tingkat', 'parent_kode', 'latitude', 'longitude'];

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
}