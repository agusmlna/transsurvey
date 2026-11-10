<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Mail\Mailable;

/**
 * Email CSAT untuk satu tiket.
 *
 * Berbeda dari undangan survei client, email ini tidak memakai kode akses:
 * tautan token sudah cukup karena hanya berlaku untuk satu tiket dan customer.
 */
class CsatInvitationMail extends Mailable
{
    public function __construct(public Invitation $invitation) {}

    public function build()
    {
        $ticket = $this->invitation->ticket_id ?: '-';

        return $this->subject("Survei Kepuasan – Tiket {$ticket} / Satisfaction Survey – Ticket {$ticket}")
            ->view('mail.csat')
            ->with(['invitation' => $this->invitation]);
    }
}
