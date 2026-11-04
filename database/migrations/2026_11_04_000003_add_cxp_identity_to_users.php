<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('external_source', 50)->nullable()->after('role');
            $table->string('external_id', 191)->nullable()->after('external_source');
            $table->unique(['external_source', 'external_id'], 'users_external_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_external_identity_unique');
            $table->dropColumn(['external_source', 'external_id']);
        });
    }
};
