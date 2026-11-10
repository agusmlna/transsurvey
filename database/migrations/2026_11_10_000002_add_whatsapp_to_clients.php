<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Nomor WhatsApp customer untuk kebutuhan broadcast.
     *
     * Disimpan di data client (bukan per undangan) supaya satu customer cukup
     * punya satu nomor yang bisa dipakai ulang.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('wa', 30)->nullable()->index()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['wa']);
            $table->dropColumn('wa');
        });
    }
};
