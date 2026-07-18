<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeWilayah extends Model
{
    protected $table = 'subscribe_wilayah';
    protected $fillable = ['user_id', 'wilayah_kode'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wilayah()
    {
        return $this->belongsTo(Wilayah::class, 'wilayah_kode', 'kode');
    }
}