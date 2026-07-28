<?php
namespace App\Observers;

use App\Models\User;
use App\Models\Notifikasi;

class UserObserver
{
    protected int $poinPerKuota = 50;
    protected int $kuotaMaksimal = 5;

    public function updating(User $user): void
    {
        if ($user->isDirty('poin')) {
            $kuotaLama = $user->kuota_subscribe;
            $kuotaBaru = min(1 + intdiv($user->poin, $this->poinPerKuota), $this->kuotaMaksimal);

            if ($kuotaBaru > $kuotaLama) {
                $user->kuota_subscribe = $kuotaBaru;

                // Notifikasi dikirim setelah user tersimpan (pakai event 'updated' via booted, tapi
                // supaya simpel kita pakai defer sederhana lewat static property sementara)
                static::$pendingRewardNotif[$user->id] = $kuotaBaru;
            }
        }
    }

    protected static array $pendingRewardNotif = [];

    public function updated(User $user): void
    {
        if (isset(static::$pendingRewardNotif[$user->id])) {
            $kuotaBaru = static::$pendingRewardNotif[$user->id];

            Notifikasi::create([
                'user_id' => $user->id,
                'tipe' => 'reward',
                'judul' => '🎉 Kuota Subscribe Bertambah!',
                'pesan' => "Selamat! Poin kontribusi kamu bertambah, kuota subscribe wilayah kamu sekarang jadi {$kuotaBaru} wilayah.",
            ]);

            unset(static::$pendingRewardNotif[$user->id]);
        }
    }
}