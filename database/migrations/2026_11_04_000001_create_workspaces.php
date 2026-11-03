<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    // Tables whose rows carry their own workspace. Others follow through relations.
    private const TABLES = ['surveys', 'clients', 'invitations', 'survey_responses', 'bank_questions', 'data_deletion_audits'];

    public function up(): void
    {
        if (!Schema::hasTable('workspaces')) {
            Schema::create('workspaces', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code', 100)->unique();
                $table->boolean('is_legacy')->default(false);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('workspace_user')) {
            Schema::create('workspace_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['workspace_id', 'user_id']);
            });
        }
        foreach (self::TABLES as $name) {
            if (!Schema::hasColumn($name, 'workspace_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->foreignId('workspace_id')->nullable()->index()->constrained());
            }
        }

        // Every existing row belongs to the legacy workspace. Safe to run again.
        $legacy = DB::table('workspaces')->where('is_legacy', true)->value('id')
            ?? DB::table('workspaces')->insertGetId(['name' => 'Data lama', 'code' => 'legacy', 'is_legacy' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (self::TABLES as $name) {
            DB::table($name)->whereNull('workspace_id')->update(['workspace_id' => $legacy]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasColumn($name, 'workspace_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropForeign(['workspace_id']);
                    $table->dropIndex(['workspace_id']);
                    $table->dropColumn('workspace_id');
                });
            }
        }
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
    }
};
