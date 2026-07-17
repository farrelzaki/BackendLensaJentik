<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('laporan_warga', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // null = anonim
            $table->string('wilayah_kode', 15);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('foto_path');
            $table->text('deskripsi')->nullable();
            $table->enum('status', ['belum_ditangani', 'sedang_diproses', 'selesai'])->default('belum_ditangani');
            $table->integer('jumlah_verifikasi')->default(0);
            $table->timestamps();

            $table->foreign('wilayah_kode')->references('kode')->on('wilayah')->cascadeOnDelete();
            $table->index(['wilayah_kode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laporan_warga');
    }
};