<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbjLaporan extends Model
{
    protected $table = 'abj_laporan';
    protected $fillable = ['kader_id', 'wilayah_kode', 'tanggal_pemeriksaan', 'jumlah_rumah_diperiksa', 'jumlah_rumah_positif_jentik', 'abj_persen', 'catatan'];
    protected $casts = ['tanggal_pemeriksaan' => 'date'];

    public function kader()
    {
        return $this->belongsTo(User::class, 'kader_id');
    }

    public function wilayah()
    {
        return $this->belongsTo(Wilayah::class, 'wilayah_kode', 'kode');
    }
}