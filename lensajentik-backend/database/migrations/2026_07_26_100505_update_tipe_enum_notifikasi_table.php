<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE notifikasi DROP CONSTRAINT IF EXISTS notifikasi_tipe_check');
            DB::statement("ALTER TABLE notifikasi ADD CONSTRAINT notifikasi_tipe_check CHECK (tipe IN ('kenaikan_risiko', 'cuaca_ekstrem', 'info', 'reminder'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE notifikasi DROP CONSTRAINT IF EXISTS notifikasi_tipe_check');
            DB::statement("ALTER TABLE notifikasi ADD CONSTRAINT notifikasi_tipe_check CHECK (tipe IN ('kenaikan_risiko', 'cuaca_ekstrem', 'info'))");
        }
    }
};