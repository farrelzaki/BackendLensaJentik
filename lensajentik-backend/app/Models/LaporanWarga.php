<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaporanWarga extends Model
{
    protected $table = 'laporan_warga';
    protected $fillable = [
        'user_id', 'session_id', 'nama_pelapor', 'wilayah_kode',
        'latitude', 'longitude', 'foto_path', 'alamat_text',
        'deskripsi', 'is_anonim', 'status', 'jumlah_verifikasi',
    ];

    protected $casts = [
        'is_anonim' => 'boolean',
    ];

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
        return $this->hasMany(VerifikasiLaporan::class, 'laporan_warga_id');
    }
}