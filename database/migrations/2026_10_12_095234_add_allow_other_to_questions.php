<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['questions', 'bank_questions'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->boolean('allow_other')->default(false)->after('options'));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       foreach (['questions', 'bank_questions'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('allow_other'));
        }
    }
};