<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifikasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('wilayah_kode', 15)->nullable();
            $table->enum('tipe', ['kenaikan_risiko', 'cuaca_ekstrem', 'info']);
            $table->string('judul');
            $table->text('pesan');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->foreign('wilayah_kode')->references('kode')->on('wilayah')->nullOnDelete();
            $table->index(['user_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifikasi');
    }
};