<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Mail\Mailable;

class SurveyInvitationMail extends Mailable
{
    public function __construct(
        public Invitation $invitation,
        public bool $reminder = false,
        public string $kind = 'invitation',
    ) {}

    public function build()
    {
        // Subjek dwibahasa: Indonesia dulu, lalu Inggris (sesuai template email TCID).
        $subject = match ($this->resolvedKind()) {
            'not_started' => 'Pengingat – Client Satisfaction Survey TCID 2026 / Reminder – TCID 2026 Client Satisfaction Survey',
            'save_draft'  => 'Pengingat untuk Melanjutkan – Client Satisfaction Survey TCID 2026 / Reminder to Continue – TCID 2026 Client Satisfaction Survey',
            default       => 'Undangan – Client Satisfaction Survey TCID 2026 / Invitation – TCID 2026 Client Satisfaction Survey',
        };

        return $this->subject($subject)
            ->view('mail.invitation')
            ->with(['emailKind' => $this->resolvedKind()]);
    }

    /**
     * Pemetaan jenis email ke template:
     *  - invitation  => Email 1 (Undangan)
     *  - not_started => Email 2 (Pengingat belum mulai)
     *  - save_draft  => Email 3 (Pengingat lanjutkan draf)
     *  - reminder    => tombol "Send reminder to contact" memakai template Email 2
     */
    private function resolvedKind(): string
    {
        return match (true) {
            $this->kind === 'save_draft' => 'save_draft',
            $this->kind === 'not_started',
            $this->kind === 'reminder',
            ($this->kind === 'invitation' && $this->reminder) => 'not_started',
            default => 'invitation',
        };
    }
}