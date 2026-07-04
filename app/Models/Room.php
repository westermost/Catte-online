<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Room extends Model
{
    public const SEAT_OCCUPYING_STATUSES = ['connected', 'disconnected', 'eliminated', 'kicked'];
    public const WAITING_ROOM_STALE_SECONDS = 30;
    public const NEXT_GAME_READY_TIMEOUT_SECONDS = 30;

    protected $fillable = [
        'code',
        'name',
        'max_players',
        'is_private',
        'thoi_ach_enabled',
        'status',
        'owner_player_id',
        'next_game_deadline_at',
    ];

    protected $casts = [
        'max_players' => 'integer',
        'is_private' => 'boolean',
        'thoi_ach_enabled' => 'boolean',
        'next_game_deadline_at' => 'datetime',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function activePlayers(): HasMany
    {
        return $this->hasMany(Player::class)->where('status', 'connected');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'owner_player_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    public function isJoinable(): bool
    {
        return $this->status === 'waiting' && !$this->isFull();
    }

    public function isFull(): bool
    {
        return $this->players()
            ->whereIn('status', self::SEAT_OCCUPYING_STATUSES)
            ->count() >= $this->max_players;
    }

    public function getNextSeatPosition(): ?int
    {
        // Check all players that still occupy a playable/reconnectable seat.
        $occupied = $this->players()
            ->whereIn('status', self::SEAT_OCCUPYING_STATUSES)
            ->pluck('seat_position')
            ->toArray();

        for ($i = 0; $i < $this->max_players; $i++) {
            if (!in_array($i, $occupied)) {
                return $i;
            }
        }

        return null;
    }

    public function getReleasedSeatPosition(int $exceptPlayerId): int
    {
        $occupied = $this->players()
            ->where('id', '!=', $exceptPlayerId)
            ->pluck('seat_position')
            ->toArray();

        for ($i = $this->max_players; $i <= 255; $i++) {
            if (!in_array($i, $occupied)) {
                return $i;
            }
        }

        throw new \RuntimeException('No released seat position available.');
    }

    public static function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
