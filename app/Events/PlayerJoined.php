<?php

namespace App\Events;

use App\Models\Player;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Player $player,
        public int $roomId,
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
            'player' => [
                'id' => $this->player->id,
                'name' => $this->player->name,
                'seat_position' => $this->player->seat_position,
                'status' => $this->player->status,
                'ready_for_next_game' => $this->player->ready_for_next_game,
            ],
        ];
    }
}
