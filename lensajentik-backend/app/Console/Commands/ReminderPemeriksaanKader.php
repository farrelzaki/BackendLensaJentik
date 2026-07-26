<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Models\Notifikasi;
use App\Mail\NotifikasiEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ReminderPemeriksaanKader extends Command
{
    protected $signature = 'reminder-kader:cek';
    protected $description = 'Kirim reminder ke kader yang belum input ABJ 7 hari terakhir di wilayah tugasnya';

    public function handle(): int
    {
        $kaderList = User::where('role', 'kader')
            ->where('is_active', true)
            ->whereNotNull('wilayah_kode')
            ->with('wilayahTugas:kode,nama')
            ->get();

        $this->info("Mengecek {$kaderList->count()} kader...");
        $terkirim = 0;

        foreach ($kaderList as $kader) {
            $sudahInput = $kader->abjLaporan()
                ->where('wilayah_kode', $kader->wilayah_kode)
                ->where('tanggal_pemeriksaan', '>=', now()->subDays(7))
                ->exists();

            if ($sudahInput) {
                continue; // udah rajin input, skip
            }

            $namaWilayah = $kader->wilayahTugas->nama ?? 'wilayah tugas kamu';

            $notif = Notifikasi::create([
                'user_id' => $kader->id,
                'wilayah_kode' => $kader->wilayah_kode,
                'tipe' => 'reminder',
                'judul' => '🔔 Waktunya Periksa Jentik!',
                'pesan' => "Sudah lebih dari 7 hari kamu belum input data ABJ untuk {$namaWilayah}. Yuk lakukan pemeriksaan jentik berkala dan catat hasilnya di LensaJentik.",
            ]);

            try {
                Mail::to($kader->email)->send(new NotifikasiEmail($notif));
                $terkirim++;
            } catch (\Exception $e) {
                $this->error("Gagal kirim email ke {$kader->email}: {$e->getMessage()}");
            }
        }

        $this->info("Reminder terkirim ke {$terkirim} kader.");
        return self::SUCCESS;
    }
}