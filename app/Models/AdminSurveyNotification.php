<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminSurveyNotification extends Model
{
    protected $fillable = ['survey_response_id', 'user_id', 'recipient_email', 'status', 'queued_at', 'sent_at'];

    protected function casts(): array
    {
        return ['queued_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function response() { return $this->belongsTo(SurveyResponse::class, 'survey_response_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
