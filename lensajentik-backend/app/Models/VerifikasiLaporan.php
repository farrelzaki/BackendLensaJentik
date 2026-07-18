<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerifikasiLaporan extends Model
{
    protected $table = 'verifikasi_laporan';
    protected $fillable = ['laporan_id', 'user_id'];

    public function laporan()
    {
        return $this->belongsTo(LaporanWarga::class, 'laporan_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}