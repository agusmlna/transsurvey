<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->string('type', 20)->default('client')->index()->after('title');
            // CSAT templates have no end date; client surveys keep requiring it in validation.
            $table->date('ends_at')->nullable()->change();
        });

        Schema::table('invitations', function (Blueprint $table) {
            // Empty string, not NULL, for client surveys: NULLs never collide in a unique
            // index, which would silently drop the one-invitation-per-client rule.
            $table->string('ticket_id', 100)->default('')->after('client_id');
        });
        DB::table('invitations')->whereNull('ticket_id')->update(['ticket_id' => '']);
        Schema::table('invitations', function (Blueprint $table) {
            // Add the new index first: it still starts with survey_id for the foreign key.
            $table->unique(['survey_id', 'client_id', 'ticket_id'], 'invitations_survey_client_ticket_unique');
        });
        Schema::table('invitations', fn (Blueprint $table) => $table->dropUnique(['survey_id', 'client_id']));
    }

    public function down(): void
    {
        // A customer may have several ticket invitations; the old index cannot hold them.
        abort_if(DB::table('invitations')->where('ticket_id', '!=', '')->exists(), 500, 'Hapus undangan CSAT (ticket_id) sebelum rollback.');
        abort_if(DB::table('surveys')->whereNull('ends_at')->exists(), 500, 'Isi ends_at semua survei sebelum rollback.');
        Schema::table('invitations', fn (Blueprint $table) => $table->unique(['survey_id', 'client_id']));
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropUnique('invitations_survey_client_ticket_unique');
            $table->dropColumn('ticket_id');
        });
        Schema::table('surveys', function (Blueprint $table) {
            $table->date('ends_at')->nullable(false)->change();
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
