<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Data tiket dan masa berlaku tautan untuk undangan CSAT.
     *
     * expires_at null berarti tautan tanpa batas waktu. Kolom ini diisi saat
     * email CSAT berhasil dikirim, bukan saat undangan dibuat.
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->string('ticket_status', 100)->nullable();
            $table->string('channel', 100)->nullable();
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['ticket_status', 'channel', 'expires_at']);
        });
    }
};
