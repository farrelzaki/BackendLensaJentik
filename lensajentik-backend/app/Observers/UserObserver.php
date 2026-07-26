<?php
namespace App\Observers;

use App\Models\User;

class UserObserver
{
    protected int $poinPerKuota = 50; // tiap 50 poin, nambah 1 kuota subscribe
    protected int $kuotaMaksimal = 5; // batas atas biar gak infinite

    public function updating(User $user): void
    {
        if ($user->isDirty('poin')) {
            $kuotaBaru = 1 + intdiv($user->poin, $this->poinPerKuota);
            $user->kuota_subscribe = min($kuotaBaru, $this->kuotaMaksimal);
        }
    }
}