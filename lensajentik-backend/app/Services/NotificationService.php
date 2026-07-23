<?php
namespace App\Services;

use App\Models\Wilayah;
use App\Models\Notifikasi;
use App\Models\SubscribeWilayah;
use App\Mail\NotifikasiEmail;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Kirim notifikasi ke semua user yang subscribe ke wilayah tertentu.
     */
    public function kirimKeSubscriber(Wilayah $wilayah, string $tipe, string $judul, string $pesan): void
    {
        $subscribers = SubscribeWilayah::where('wilayah_kode', $wilayah->kode)
            ->with('user')
            ->get();

        foreach ($subscribers as $sub) {
            $notif = Notifikasi::create([
                'user_id' => $sub->user_id,
                'wilayah_kode' => $wilayah->kode,
                'tipe' => $tipe,
                'judul' => $judul,
                'pesan' => $pesan,
            ]);

            if ($sub->user && $sub->user->email) {
                try {
                    Mail::to($sub->user->email)->send(new NotifikasiEmail($notif));
                } catch (\Exception $e) {
                    // Gagal kirim email gak boleh gagalkan proses utama (notif in-app tetap tersimpan)
                    logger()->warning("Gagal kirim email notifikasi ke {$sub->user->email}: {$e->getMessage()}");
                }
            }
        }
    }

    public function notifikasiKenaikanRisiko(Wilayah $wilayah, string $jenisPenyakit, string $levelLama, string $levelBaru): void
    {
        $judul = "⚠️ Risiko " . strtoupper($jenisPenyakit) . " Naik di {$wilayah->nama}";
        $pesan = "Skor risiko {$jenisPenyakit} di {$wilayah->nama} naik dari level {$levelLama} ke {$levelBaru}. Waspada dan lakukan pencegahan 3M Plus.";

        $this->kirimKeSubscriber($wilayah, 'kenaikan_risiko', $judul, $pesan);
    }

    public function notifikasiCuacaEkstrem(Wilayah $wilayah, float $curahHujan): void
    {
        $judul = "🌧️ Peringatan Cuaca Ekstrem di {$wilayah->nama}";
        $pesan = "Curah hujan tinggi ({$curahHujan}mm) terdeteksi di {$wilayah->nama}. Potensi genangan air meningkat, periksa lingkungan sekitar rumah.";

        $this->kirimKeSubscriber($wilayah, 'cuaca_ekstrem', $judul, $pesan);
    }
}   