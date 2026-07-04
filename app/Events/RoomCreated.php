<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $room,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('lobby'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'room' => $this->room,
        ];
    }
}
