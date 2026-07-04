<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Play extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'round_id',
        'player_id',
        'card',
        'is_face_down',
        'play_order',
    ];

    protected $casts = [
        'is_face_down' => 'boolean',
        'play_order' => 'integer',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
