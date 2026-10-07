<?php

namespace App\Mail;

use App\Models\SurveyResponse;
use Illuminate\Mail\Mailable;

class SurveyCompletedMail extends Mailable
{
    public function __construct(public SurveyResponse $response, public string $adminName) {}

    public function build()
    {
        // Use submitted answer snapshots, not the average score or an edited questionnaire.
        // Query explicitly so a previously loaded subset of answers cannot hide a low rating.
        $lowRatings = $this->response->answers()
            ->where('type', 'rating')
            ->whereIn('value', ['1', '2', '3'])
            ->orderBy('id')
            ->get();

        $subject = $lowRatings->isNotEmpty()
            ? '[Perlu tindak lanjut] Rating di bawah 4: '.$this->response->survey->title
            : 'Survei selesai: '.$this->response->survey->title;

        // Reuse the existing per-response/admin notification and its duplicate protection.
        return $this->subject($subject)
            ->view('mail.survey-completed')
            ->with(['lowRatings' => $lowRatings]);
    }
}
