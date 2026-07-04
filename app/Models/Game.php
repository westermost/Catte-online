<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    const CREATED_AT = null;
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'room_id',
        'game_number',
        'phase',
        'current_round',
        'current_player_id',
        'hands',
        'instant_winner_id',
        'instant_win_type',
        'winner_id',
        'turn_started_at',
        'started_at',
    ];

    protected $casts = [
        'hands' => 'encrypted:array',
        'game_number' => 'integer',
        'current_round' => 'integer',
        'turn_started_at' => 'datetime',
        'started_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    public function currentPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'current_player_id');
    }
}
