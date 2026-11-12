<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot tiket Close TransCXP per project.
 *
 * Model ini sengaja tidak memakai BelongsToWorkspace: datanya bukan milik
 * workspace tertentu dan dibaca lintas workspace oleh command.
 */
class CxpTicketSeen extends Model
{
    protected $table = 'cxp_ticket_seen';

    protected $fillable = ['project_code', 'ticket_id', 'first_seen_at'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime'];
    }
}
