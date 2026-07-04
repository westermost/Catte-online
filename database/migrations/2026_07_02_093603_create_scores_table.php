<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('guest_token');
            $table->string('player_name');
            $table->integer('total_points')->default(0);
            $table->unsignedInteger('games_won')->default(0);
            $table->unsignedInteger('games_lost')->default(0);
            $table->unsignedInteger('tung_deaths')->default(0);
            $table->unsignedInteger('thoi_ach_count')->default(0);
            $table->unsignedInteger('instant_wins')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['room_id', 'guest_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
