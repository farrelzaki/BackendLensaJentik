<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notifikasi extends Model
{
    protected $table = 'notifikasi';
    protected $fillable = ['user_id', 'wilayah_kode', 'tipe', 'judul', 'pesan', 'metadata', 'is_dibaca'];

    protected $casts = [
        'is_dibaca' => 'boolean',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wilayah()
    {
        return $this->belongsTo(Wilayah::class, 'wilayah_kode', 'kode');
    }
}