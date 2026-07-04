<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('max_players')->default(4);
            $table->boolean('is_private')->default(false);
            $table->boolean('thoi_ach_enabled')->default(false);
            $table->string('status')->default('waiting'); // waiting, playing, finished
            $table->unsignedBigInteger('owner_player_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
