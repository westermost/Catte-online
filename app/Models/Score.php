<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Score extends Model
{
    const UPDATED_AT = 'updated_at';
    const CREATED_AT = null;

    protected $fillable = [
        'room_id',
        'guest_token',
        'player_name',
        'total_points',
        'games_won',
        'games_lost',
        'tung_deaths',
        'thoi_ach_count',
        'instant_wins',
    ];

    protected $hidden = [
        'guest_token',
    ];

    protected $casts = [
        'total_points' => 'integer',
        'games_won' => 'integer',
        'games_lost' => 'integer',
        'tung_deaths' => 'integer',
        'thoi_ach_count' => 'integer',
        'instant_wins' => 'integer',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
