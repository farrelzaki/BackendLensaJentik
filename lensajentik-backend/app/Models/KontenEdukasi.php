<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KontenEdukasi extends Model
{
    protected $table = 'konten_edukasi';
    protected $fillable = ['tipe', 'judul', 'slug', 'ringkasan', 'isi', 'sumber', 'gambar_url'];
}