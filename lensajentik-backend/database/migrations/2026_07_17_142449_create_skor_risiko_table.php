<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('skor_risiko', function (Blueprint $table) {
            $table->id();
            $table->string('wilayah_kode', 15);
            $table->enum('jenis_penyakit', ['dbd', 'malaria']);
            $table->date('tanggal');
            $table->decimal('skor', 5, 2); // 0-100
            $table->enum('level_risiko', ['rendah', 'sedang', 'tinggi']);
            $table->enum('confidence_level', ['kuat', 'lemah']); // kuat=data lokal historis, lemah=estimasi umum
            $table->boolean('is_prediksi')->default(false); // false=skor saat ini, true=proyeksi 7-14 hari
            $table->json('faktor_perhitungan')->nullable(); // simpan breakdown: suhu, kelembapan, abj, dll
            $table->timestamps();

            $table->foreign('wilayah_kode')->references('kode')->on('wilayah')->cascadeOnDelete();
            $table->index(['wilayah_kode', 'jenis_penyakit', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skor_risiko');
    }
};