<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'wilayah_kode', 'poin', 'kuota_subscribe', 'phone',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function wilayahTugas()
    {
        return $this->belongsTo(Wilayah::class, 'wilayah_kode', 'kode');
    }

    public function abjLaporan()
    {
        return $this->hasMany(AbjLaporan::class, 'kader_id');
    }

    public function laporanWarga()
    {
        return $this->hasMany(LaporanWarga::class);
    }

    public function subscribeWilayah()
    {
        return $this->hasMany(SubscribeWilayah::class);
    }

    public function isKader(): bool { return $this->role === 'kader'; }
    public function isAdmin(): bool { return in_array($this->role, ['admin_puskesmas', 'admin_dinkes']); }
}