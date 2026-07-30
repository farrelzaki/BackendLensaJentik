<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerifikasiLaporan extends Model
{
    protected $table = 'verifikasi_laporan';
    protected $fillable = ['laporan_warga_id', 'user_id', 'status_verifikasi', 'catatan'];

    public function laporan()
    {
        return $this->belongsTo(LaporanWarga::class, 'laporan_warga_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}