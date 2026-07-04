<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->unsignedInteger('game_number')->default(1);
            $table->string('phase')->default('instant_win_check'); // instant_win_check, playing, chung, finished
            $table->unsignedTinyInteger('current_round')->default(1);
            $table->unsignedBigInteger('current_player_id')->nullable();
            $table->text('hands'); // encrypted:array cast - stores base64 encrypted string, not raw JSON
            $table->unsignedBigInteger('instant_winner_id')->nullable();
            $table->string('instant_win_type')->nullable();
            $table->unsignedBigInteger('winner_id')->nullable();
            $table->timestamp('turn_started_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('room_id');
            $table->index('current_player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
