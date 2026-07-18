<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaporanWarga extends Model
{
    protected $table = 'laporan_warga';
    protected $fillable = ['user_id', 'wilayah_kode', 'latitude', 'longitude', 'foto_path', 'deskripsi', 'status', 'jumlah_verifikasi'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wilayah()
    {
        return $this->belongsTo(Wilayah::class, 'wilayah_kode', 'kode');
    }

    public function verifikasi()
    {
        return $this->hasMany(VerifikasiLaporan::class, 'laporan_id');
    }
}