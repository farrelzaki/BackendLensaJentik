<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['warga', 'kader', 'admin_puskesmas', 'admin_dinkes'])->default('warga')->after('email');
            $table->string('wilayah_kode', 15)->nullable()->after('role'); // wilayah tugas kader/admin
            $table->integer('poin')->default(0)->after('wilayah_kode');
            $table->integer('kuota_subscribe')->default(1)->after('poin');
            $table->string('phone')->nullable()->after('kuota_subscribe');

            $table->foreign('wilayah_kode')->references('kode')->on('wilayah')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['wilayah_kode']);
            $table->dropColumn(['role', 'wilayah_kode', 'poin', 'kuota_subscribe', 'phone']);
        });
    }
};