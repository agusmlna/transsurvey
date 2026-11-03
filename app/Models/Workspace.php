<?php

namespace App\Models;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    protected $fillable = ['name', 'code', 'is_legacy', 'settings'];

    protected function casts(): array
    {
        return ['is_legacy' => 'boolean', 'settings' => 'array'];
    }

    protected static function booted(): void
    {
        // A new workspace starts with the default question bank instead of an empty one.
        static::created(function (Workspace $workspace) {
            if ($workspace->is_legacy) return;
            foreach (DatabaseSeeder::questions() as $question) {
                $workspace->bankQuestions()->create($question);
            }
        });
    }

    public function users() { return $this->belongsToMany(User::class, 'workspace_user')->withTimestamps(); }
    public function bankQuestions() { return $this->hasMany(BankQuestion::class); }
}
