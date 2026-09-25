<?php
namespace App\Mail;
use App\Models\Invitation;
use Illuminate\Mail\Mailable;
class SurveyInvitationMail extends Mailable {
 public function __construct(public Invitation $invitation,public bool $reminder=false){}
 public function build(){return $this->subject(($this->reminder?'Pengingat: ':'Undangan: ').$this->invitation->survey->title)->view('mail.invitation');}
}
