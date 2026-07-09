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
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->string('invocation_id')->nullable()->after('error');
            $table->index('invocation_id');
        });

        Schema::table('ai_usages', function (Blueprint $table): void {
            $table->dropForeign(['ai_run_id']);
            $table->string('invocation_id')->nullable()->after('ai_run_id');
            $table->unique('invocation_id');
            $table->foreignId('ai_run_id')->nullable()->change();
            $table->foreign('ai_run_id')->references('id')->on('ai_runs')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_usages', function (Blueprint $table): void {
            $table->dropForeign(['ai_run_id']);
            $table->dropUnique(['invocation_id']);
            $table->dropColumn('invocation_id');
            $table->foreignId('ai_run_id')->nullable(false)->change();
            $table->foreign('ai_run_id')->references('id')->on('ai_runs')->cascadeOnDelete();
        });

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropIndex(['invocation_id']);
            $table->dropColumn('invocation_id');
        });
    }
};
