<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Room $room,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("room.{$this->room->id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'room' => [
                'id' => $this->room->id,
                'status' => $this->room->status,
                'owner_player_id' => $this->room->owner_player_id,
                'next_game_deadline_at' => $this->room->next_game_deadline_at?->toIso8601String(),
            ],
        ];
    }
}
