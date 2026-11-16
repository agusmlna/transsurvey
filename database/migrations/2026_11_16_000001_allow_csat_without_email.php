<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    /**
     * Tiket TransCXP tidak selalu punya email. Jalur tautan manual hanya
     * membutuhkan identitas customer (email, nomor WA, atau nama), sedangkan
     * jalur email tetap mewajibkan email di lapisan aplikasi.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->string('recipient_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Isi nilai kosong supaya kolom bisa kembali NOT NULL tanpa gagal.
        DB::table('clients')->whereNull('email')->update(['email' => '']);
        DB::table('invitations')->whereNull('recipient_email')->update(['recipient_email' => '']);

        Schema::table('clients', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->string('recipient_email')->nullable(false)->change();
        });
    }
};
