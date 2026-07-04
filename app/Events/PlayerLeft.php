<?php

namespace App\Events;

use App\Models\Player;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerLeft implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $playerId,
        public int $roomId,
        public string $playerName,
        public ?int $newOwnerId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("room.{$this->roomId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'new_owner_id' => $this->newOwnerId,
        ];
    }
}
