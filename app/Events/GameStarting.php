<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameStarting implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $gameId,
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
            'game_id' => $this->gameId,
        ];
    }
}
