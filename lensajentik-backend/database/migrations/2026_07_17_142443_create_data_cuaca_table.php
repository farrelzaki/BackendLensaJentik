<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('data_cuaca', function (Blueprint $table) {
            $table->id();
            $table->string('wilayah_kode', 15);
            $table->date('tanggal');
            $table->decimal('suhu_avg', 5, 2)->nullable();
            $table->decimal('kelembapan_avg', 5, 2)->nullable();
            $table->decimal('curah_hujan', 6, 2)->nullable();
            $table->boolean('is_forecast')->default(false); // false = data historis, true = prediksi
            $table->string('sumber_api', 50)->default('open-meteo');
            $table->timestamps();

            $table->foreign('wilayah_kode')->references('kode')->on('wilayah')->cascadeOnDelete();
            $table->unique(['wilayah_kode', 'tanggal', 'is_forecast']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_cuaca');
    }
};