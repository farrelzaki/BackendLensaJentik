<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrediksiRisiko extends Model
{
    protected $table = 'prediksi_risiko';

    protected $fillable = [
        'wilayah_kode',
        'jenis_penyakit',
        'tanggal_prediksi',
        'tanggal_perhitungan',
        'skor',
        'level_risiko',
        'confidence_level',
        'faktor_perhitungan',
    ];

    protected $casts = [
        'tanggal_prediksi'     => 'date',
        'tanggal_perhitungan'  => 'date',
        'faktor_perhitungan'   => 'array',
    ];

    public function wilayah(): BelongsTo
    {
        return $this->belongsTo(Wilayah::class, 'wilayah_kode', 'kode');
    }
}
