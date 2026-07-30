<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KontenEdukasi extends Model
{
    protected $table = 'konten_edukasi';
    protected $fillable = ['kategori', 'judul', 'slug', 'ringkasan', 'konten', 'sumber', 'gambar_url'];
}