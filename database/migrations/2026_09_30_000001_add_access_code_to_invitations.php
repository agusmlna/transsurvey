<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->text('access_code')->nullable();
            $table->string('access_code_hash')->nullable();
            $table->string('access_code_fingerprint', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropUnique(['access_code_fingerprint']);
            $table->dropColumn(['access_code', 'access_code_hash', 'access_code_fingerprint']);
        });
    }
};
