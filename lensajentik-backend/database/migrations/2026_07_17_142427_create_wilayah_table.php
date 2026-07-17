<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wilayah', function (Blueprint $table) {
            $table->string('kode', 15)->primary();
            $table->string('nama');
            $table->enum('tingkat', ['provinsi', 'kabupaten', 'kecamatan', 'desa']);
            $table->string('parent_kode', 15)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index('parent_kode'); // index biasa, tanpa FK constraint
            $table->index('tingkat');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wilayah');
    }
};