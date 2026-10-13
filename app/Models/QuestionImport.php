<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionImport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'immutable_datetime', 'imported_at' => 'immutable_datetime'];
    }
}
