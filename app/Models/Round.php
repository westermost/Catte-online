<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'game_id',
        'round_number',
        'lead_player_id',
        'participant_count',
        'lead_suit',
        'winner_id',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'round_number' => 'integer',
        'participant_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class)->orderBy('play_order');
    }

    public function leadPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'lead_player_id');
    }
}
