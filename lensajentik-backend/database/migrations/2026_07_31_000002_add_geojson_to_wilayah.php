<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('wilayah', 'geojson')) {
            Schema::table('wilayah', function (Blueprint $table) {
                $table->jsonb('geojson')->nullable()->after('elevasi');
                $table->timestamp('geojson_fetched_at')->nullable()->after('geojson');
            });
        }
    }

    public function down(): void
    {
        Schema::table('wilayah', function (Blueprint $table) {
            $table->dropColumn(['geojson', 'geojson_fetched_at']);
        });
    }
};
