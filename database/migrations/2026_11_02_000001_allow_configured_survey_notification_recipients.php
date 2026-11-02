<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    // Completion emails may go to configured addresses that have no user account.
    public function up(): void
    {
        Schema::table('admin_survey_notifications', function (Blueprint $table) {
            // Add the new unique index first: it still starts with survey_response_id for the foreign key.
            $table->unique(['survey_response_id', 'recipient_email'], 'admin_survey_notification_recipient_unique');
        });
        Schema::table('admin_survey_notifications', function (Blueprint $table) {
            $table->dropUnique('admin_survey_notification_unique');
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Configured-address rows have no user and cannot exist in the old schema.
        DB::table('admin_survey_notifications')->whereNull('user_id')->delete();
        Schema::table('admin_survey_notifications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->unique(['survey_response_id', 'user_id'], 'admin_survey_notification_unique');
        });
        Schema::table('admin_survey_notifications', function (Blueprint $table) {
            $table->dropUnique('admin_survey_notification_recipient_unique');
        });
    }
};
