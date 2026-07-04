<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TurnStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $gameId,
        public int $playerId,
        public $turnStartedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->roomId}")];
    }

    public function broadcastWith(): array
    {
        return [
            'game_id' => $this->gameId,
            'player_id' => $this->playerId,
            'turn_started_at' => $this->turnStartedAt?->toIso8601String(),
        ];
    }
}
