<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkorRisiko extends Model
{
    protected $table = 'skor_risiko';
    protected $fillable = ['wilayah_kode', 'jenis_penyakit', 'tanggal', 'skor', 'level_risiko', 'confidence_level', 'is_prediksi', 'faktor_perhitungan'];
    protected $casts = ['tanggal' => 'date', 'is_prediksi' => 'boolean', 'faktor_perhitungan' => 'array'];

    public function wilayah()
    {
        return $this->belongsTo(Wilayah::class, 'wilayah_kode', 'kode');
    }
}