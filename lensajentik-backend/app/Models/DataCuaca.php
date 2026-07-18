<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataCuaca extends Model
{
    protected $table = 'data_cuaca';
    protected $fillable = ['wilayah_kode', 'tanggal', 'suhu_avg', 'kelembapan_avg', 'curah_hujan', 'is_forecast', 'sumber_api'];
    protected $casts = ['tanggal' => 'date', 'is_forecast' => 'boolean'];

    public function wilayah()
    {
        return $this->belongsTo(Wilayah::class, 'wilayah_kode', 'kode');
    }
}