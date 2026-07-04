<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('players', 'ready_for_next_game')) {
            Schema::table('players', function (Blueprint $table) {
                $table->boolean('ready_for_next_game')->default(false)->after('timeout_count');
            });
        }

        if (!Schema::hasColumn('rooms', 'next_game_deadline_at')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->timestamp('next_game_deadline_at')->nullable()->after('owner_player_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rooms', 'next_game_deadline_at')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->dropColumn('next_game_deadline_at');
            });
        }

        if (Schema::hasColumn('players', 'ready_for_next_game')) {
            Schema::table('players', function (Blueprint $table) {
                $table->dropColumn('ready_for_next_game');
            });
        }
    }
};
