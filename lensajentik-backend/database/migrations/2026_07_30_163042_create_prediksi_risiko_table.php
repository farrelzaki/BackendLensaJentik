<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediksi_risiko', function (Blueprint $table) {
            $table->id();
            $table->string('wilayah_kode', 15);
            $table->enum('jenis_penyakit', ['dbd', 'malaria']);
            $table->date('tanggal_prediksi');
            $table->date('tanggal_perhitungan');
            $table->decimal('skor', 5, 2);
            $table->enum('level_risiko', ['rendah', 'sedang', 'tinggi']);
            $table->enum('confidence_level', ['kuat', 'lemah']);
            $table->json('faktor_perhitungan')->nullable();
            $table->timestamps();

            $table->foreign('wilayah_kode')
                ->references('kode')
                ->on('wilayah')
                ->onDelete('cascade');

            $table->unique(
                ['wilayah_kode', 'jenis_penyakit', 'tanggal_prediksi', 'tanggal_perhitungan'],
                'prediksi_risiko_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediksi_risiko');
    }
};
