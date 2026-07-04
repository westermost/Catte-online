<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Player extends Model
{
    protected $fillable = [
        'room_id',
        'session_id',
        'guest_token',
        'name',
        'seat_position',
        'status',
        'timeout_count',
        'ready_for_next_game',
        'last_active_at',
    ];

    protected $hidden = [
        'session_id',
        'guest_token',
    ];

    protected $casts = [
        'seat_position' => 'integer',
        'timeout_count' => 'integer',
        'ready_for_next_game' => 'boolean',
        'last_active_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['connected', 'disconnected']);
    }
}
