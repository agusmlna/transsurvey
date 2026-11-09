<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // No foreign key: surveys.workspace_id already references workspaces, so a
            // second constraint would create a cycle during rollback on some engines.
            $table->unsignedBigInteger('default_csat_survey_id')->nullable()->index()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex(['default_csat_survey_id']);
            $table->dropColumn('default_csat_survey_id');
        });
    }
};
