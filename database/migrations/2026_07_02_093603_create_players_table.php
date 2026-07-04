<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('session_id')->index();
            $table->string('guest_token')->index();
            $table->string('name');
            $table->unsignedTinyInteger('seat_position');
            $table->string('status')->default('connected'); // connected, disconnected, eliminated, kicked, left
            $table->unsignedTinyInteger('timeout_count')->default(0);
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'seat_position']);
        });

        // Add foreign key for owner_player_id now that players table exists
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreign('owner_player_id')->references('id')->on('players')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['owner_player_id']);
        });
        Schema::dropIfExists('players');
    }
};
