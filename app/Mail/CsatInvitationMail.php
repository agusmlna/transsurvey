<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Services\Csat\CsatMail;
use Illuminate\Mail\Mailable;

/**
 * Email CSAT untuk satu tiket.
 *
 * Berbeda dari undangan survei client, email ini tidak memakai kode akses:
 * tautan token sudah cukup karena hanya berlaku untuk satu tiket dan customer.
 */
class CsatInvitationMail extends Mailable
{
    public function __construct(public Invitation $invitation)
    {
        // Dipasang di constructor supaya mailer dan From juga terbaca oleh
        // Mail::fake(), yang tidak memanggil build().
        $this->mailer(CsatMail::MAILER);

        // Identitas pengirim CSAT berasal dari akun SMTP khusus, bukan dari
        // from global yang dipakai email operasi.
        if (CsatMail::fromAddress() !== '') {
            $this->from(CsatMail::fromAddress(), CsatMail::fromName());
        }
    }

    public function build()
    {
        $ticket = $this->invitation->ticket_id ?: '-';

        return $this->subject("Survei Kepuasan – Tiket {$ticket} / Satisfaction Survey – Ticket {$ticket}")
            ->view('mail.csat')
            ->with(['invitation' => $this->invitation]);
    }
}
