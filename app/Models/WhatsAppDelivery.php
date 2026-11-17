<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu pesan template WhatsApp untuk satu undangan CSAT.
 *
 * Dedupe key memakai id undangan sehingga pemanggilan ulang command tidak
 * pernah mengirim pesan kedua untuk tiket yang sama.
 */
class WhatsAppDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    protected $table = 'csat_whatsapp_messages';

    protected $fillable = [
        'workspace_id',
        'invitation_id',
        'dedupe_key',
        'provider_message_id',
        'recipient_number',
        'sender_number',
        'template_id',
        'status',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'last_checked_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function invitation()
    {
        return $this->belongsTo(Invitation::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Status yang menandakan pesan sudah diterima TransCXP dan tidak boleh
     * dikirim ulang.
     */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_READ, 'replied'], true);
    }
}
