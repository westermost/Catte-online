<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('rounds')->cascadeOnDelete();
            $table->unsignedBigInteger('player_id');
            $table->string('card', 3); // e.g. AH, KS, 10D
            $table->boolean('is_face_down')->default(false);
            $table->unsignedTinyInteger('play_order');
            $table->timestamp('created_at')->nullable();

            $table->unique(['round_id', 'play_order']);
            $table->index('player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plays');
    }
};
