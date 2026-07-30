<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perbaikan skema database agar sesuai dengan SPESIFIKASI_BACKEND_LENSAJENTIK.md
 *
 * Kompatibel dengan PostgreSQL & MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // ═══════════════════════════════════════════════════════════════════
        // 0. DROP CONSTRAINTS dulu (biar update data tidak dicek)
        // ═══════════════════════════════════════════════════════════════════
        try { DB::statement('ALTER TABLE laporan_warga DROP CONSTRAINT IF EXISTS laporan_warga_status_check'); } catch (\Exception $e) {}
        try { DB::statement('ALTER TABLE skor_risiko DROP CONSTRAINT IF EXISTS skor_risiko_level_risiko_check'); } catch (\Exception $e) {}
        try { DB::statement('ALTER TABLE prediksi_risiko DROP CONSTRAINT IF EXISTS prediksi_risiko_level_risiko_check'); } catch (\Exception $e) {}

        // ═══════════════════════════════════════════════════════════════════
        // 1. USERS — rename name→nama
        // ═══════════════════════════════════════════════════════════════════
        if (Schema::hasColumn('users', 'name') && !Schema::hasColumn('users', 'nama')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('name', 'nama');
            });
        }

        // USERS — tambah status_verifikasi (default true)
        if (!Schema::hasColumn('users', 'status_verifikasi')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('status_verifikasi')->default(true)->after('is_active');
            });
        }

        // ═══════════════════════════════════════════════════════════════════
        // 2. ABJ_LAPORAN — rename columns
        // ═══════════════════════════════════════════════════════════════════
        if (Schema::hasColumn('abj_laporan', 'kader_id') && !Schema::hasColumn('abj_laporan', 'user_id')) {
            Schema::table('abj_laporan', function (Blueprint $table) {
                $table->renameColumn('kader_id', 'user_id');
            });
        }

        if (Schema::hasColumn('abj_laporan', 'jumlah_rumah_positif_jentik') && !Schema::hasColumn('abj_laporan', 'jumlah_rumah_positif')) {
            Schema::table('abj_laporan', function (Blueprint $table) {
                $table->renameColumn('jumlah_rumah_positif_jentik', 'jumlah_rumah_positif');
            });
        }

        // ═══════════════════════════════════════════════════════════════════
        // 3. LAPORAN_WARGA — tambah kolom baru, nullable-kan wilayah_kode
        // ═══════════════════════════════════════════════════════════════════
        if (!Schema::hasColumn('laporan_warga', 'session_id')) {
            Schema::table('laporan_warga', function (Blueprint $table) {
                $table->string('session_id', 100)->nullable()->after('user_id');
                $table->string('nama_pelapor', 255)->nullable()->after('session_id');
                $table->text('alamat_text')->nullable()->after('foto_path');
                $table->boolean('is_anonim')->default(false)->after('deskripsi');
            });
        }

        // Ubah `sedang_diproses` → `diproses` (constraint sudah di-drop, jadi aman)
        DB::statement("UPDATE laporan_warga SET status = 'diproses' WHERE status = 'sedang_diproses'");

        // Ubah nullable wilayah_kode
        Schema::table('laporan_warga', function (Blueprint $table) {
            $table->string('wilayah_kode', 15)->nullable()->change();
        });

        // ═══════════════════════════════════════════════════════════════════
        // 4. VERIFIKASI_LAPORAN — rename FK & tambah kolom
        // ═══════════════════════════════════════════════════════════════════
        if (Schema::hasColumn('verifikasi_laporan', 'laporan_id') && !Schema::hasColumn('verifikasi_laporan', 'laporan_warga_id')) {
            try { DB::statement('ALTER TABLE verifikasi_laporan DROP CONSTRAINT IF EXISTS verifikasi_laporan_laporan_id_user_id_unique'); } catch (\Exception $e) {}

            Schema::table('verifikasi_laporan', function (Blueprint $table) {
                $table->renameColumn('laporan_id', 'laporan_warga_id');
            });
        }

        if (!Schema::hasColumn('verifikasi_laporan', 'status_verifikasi')) {
            DB::statement("ALTER TABLE verifikasi_laporan ADD COLUMN IF NOT EXISTS status_verifikasi VARCHAR(20) DEFAULT NULL");
            DB::statement("ALTER TABLE verifikasi_laporan ADD COLUMN IF NOT EXISTS catatan TEXT DEFAULT NULL");
            try { DB::statement('ALTER TABLE verifikasi_laporan ADD CONSTRAINT verifikasi_laporan_unique UNIQUE (laporan_warga_id, user_id)'); } catch (\Exception $e) {}
        }

        // ═══════════════════════════════════════════════════════════════════
        // 5. NOTIFIKASI — rename is_read→is_dibaca, tambah metadata
        // ═══════════════════════════════════════════════════════════════════
        if (Schema::hasColumn('notifikasi', 'is_read') && !Schema::hasColumn('notifikasi', 'is_dibaca')) {
            Schema::table('notifikasi', function (Blueprint $table) {
                $table->renameColumn('is_read', 'is_dibaca');
            });
        }

        if (!Schema::hasColumn('notifikasi', 'metadata')) {
            Schema::table('notifikasi', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('pesan');
            });
        }

        // ═══════════════════════════════════════════════════════════════════
        // 6. KONTEN_EDUKASI — rename tipe→kategori, isi→konten
        // ═══════════════════════════════════════════════════════════════════
        if (Schema::hasColumn('konten_edukasi', 'tipe') && !Schema::hasColumn('konten_edukasi', 'kategori')) {
            Schema::table('konten_edukasi', function (Blueprint $table) {
                $table->renameColumn('tipe', 'kategori');
            });
        }

        if (Schema::hasColumn('konten_edukasi', 'isi') && !Schema::hasColumn('konten_edukasi', 'konten')) {
            Schema::table('konten_edukasi', function (Blueprint $table) {
                $table->renameColumn('isi', 'konten');
            });
        }

        // ═══════════════════════════════════════════════════════════════════
        // 7. Tambah constraint baru (setelah data diupdate)
        // ═══════════════════════════════════════════════════════════════════
        DB::statement("ALTER TABLE laporan_warga ADD CONSTRAINT laporan_warga_status_check CHECK (status IN ('belum_ditangani','diproses','selesai'))");
        DB::statement("ALTER TABLE skor_risiko ADD CONSTRAINT skor_risiko_level_risiko_check CHECK (level_risiko IN ('rendah','sedang','tinggi','belum_ada_data'))");
        DB::statement("ALTER TABLE prediksi_risiko ADD CONSTRAINT prediksi_risiko_level_risiko_check CHECK (level_risiko IN ('rendah','sedang','tinggi','belum_ada_data'))");
    }

    public function down(): void
    {
        // Rollback tidak diimplementasikan — ini adalah migration korektif one-way
    }
};
