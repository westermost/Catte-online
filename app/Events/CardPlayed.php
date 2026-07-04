<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CardPlayed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $gameId,
        public int $playerId,
        public ?string $card, // null if face_down (for other players)
        public bool $isFaceDown,
        public int $roundNumber,
        public int $playOrder,
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
            'card' => $this->card,
            'is_face_down' => $this->isFaceDown,
            'round_number' => $this->roundNumber,
            'play_order' => $this->playOrder,
        ];
    }
}
