<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('abj_laporan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kader_id')->constrained('users')->cascadeOnDelete();
            $table->string('wilayah_kode', 15);
            $table->date('tanggal_pemeriksaan');
            $table->integer('jumlah_rumah_diperiksa');
            $table->integer('jumlah_rumah_positif_jentik');
            $table->decimal('abj_persen', 5, 2); // (diperiksa - positif) / diperiksa * 100
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->foreign('wilayah_kode')->references('kode')->on('wilayah')->cascadeOnDelete();
            $table->index(['wilayah_kode', 'tanggal_pemeriksaan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abj_laporan');
    }
};