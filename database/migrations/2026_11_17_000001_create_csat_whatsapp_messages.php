<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Riwayat pengiriman WhatsApp untuk undangan CSAT.
     *
     * Satu undangan hanya boleh punya satu pesan WhatsApp (dedupe_key unik).
     * Kolom status mengikuti status TransCXP: pending, sent, delivered, read,
     * replied, failed, error.
     */
    public function up(): void
    {
        Schema::create('csat_whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitation_id')->constrained()->cascadeOnDelete();
            $table->string('dedupe_key', 100)->unique();
            $table->string('provider_message_id', 100)->nullable()->index();
            $table->string('recipient_number', 30);
            $table->string('sender_number', 30)->nullable();
            $table->string('template_id', 100)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['invitation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_whatsapp_messages');
    }
};
