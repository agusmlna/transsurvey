<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Snapshot tiket Close yang pernah dilihat, per project.
     *
     * Run pertama untuk sebuah project hanya mengisi tabel ini (baseline) dan
     * tidak mengirim CSAT. Run berikutnya mengirim hanya tiket yang belum ada
     * di sini. project_code disimpan huruf kecil supaya cocok dengan kode
     * workspace yang dibandingkan tanpa memandang kapitalisasi.
     */
    public function up(): void
    {
        Schema::create('cxp_ticket_seen', function (Blueprint $table) {
            $table->id();
            $table->string('project_code', 100);
            $table->string('ticket_id', 100);
            $table->timestamp('first_seen_at');
            $table->timestamps();

            $table->unique(['project_code', 'ticket_id']);
            $table->index('first_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cxp_ticket_seen');
    }
};
