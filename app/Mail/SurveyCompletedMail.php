<?php

namespace App\Mail;

use App\Models\SurveyResponse;
use Illuminate\Mail\Mailable;

class SurveyCompletedMail extends Mailable
{
    public function __construct(public SurveyResponse $response, public string $adminName) {}

    public function build()
    {
        return $this->subject('Survei selesai: '.$this->response->survey->title)
            ->view('mail.survey-completed');
    }
}
