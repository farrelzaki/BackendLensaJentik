<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('konten_edukasi', function (Blueprint $table) {
            $table->id();
            $table->enum('tipe', ['artikel', 'panduan']);
            $table->string('judul');
            $table->string('slug')->unique();
            $table->text('ringkasan');
            $table->longText('isi'); // markdown/html
            $table->string('sumber')->nullable();
            $table->string('gambar_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('konten_edukasi');
    }
};