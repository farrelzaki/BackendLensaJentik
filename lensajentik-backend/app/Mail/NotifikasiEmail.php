<?php
namespace App\Mail;

use App\Models\Notifikasi;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotifikasiEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Notifikasi $notifikasi) {}

    public function build()
    {
        return $this->subject($this->notifikasi->judul)
            ->view('emails.notifikasi')
            ->with(['notifikasi' => $this->notifikasi]);
    }
}