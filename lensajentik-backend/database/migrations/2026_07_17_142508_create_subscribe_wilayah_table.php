<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscribe_wilayah', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('wilayah_kode', 15);
            $table->timestamps();

            $table->foreign('wilayah_kode')->references('kode')->on('wilayah')->cascadeOnDelete();
            $table->unique(['user_id', 'wilayah_kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribe_wilayah');
    }
};