<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropForeign(['ticket_id']);
        });

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreignId('ticket_id')->nullable()->change();
        });

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreign('ticket_id')
                ->references('id')
                ->on('tickets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropForeign(['ticket_id']);
        });

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreignId('ticket_id')->nullable(false)->change();
        });

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreign('ticket_id')
                ->references('id')
                ->on('tickets')
                ->cascadeOnDelete();
        });
    }
};
