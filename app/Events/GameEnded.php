<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $gameId,
        public ?int $winnerId,
        public string $winType,
        public ?string $instantWinType,
        public array $hands, // reveal all hands at game end
        public array $scores,
        public array $tablePlays = [],
        public ?int $finalRoundNumber = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->roomId}")];
    }

    public function broadcastWith(): array
    {
        return [
            'game_id' => $this->gameId,
            'winner_id' => $this->winnerId,
            'win_type' => $this->winType,
            'instant_win_type' => $this->instantWinType,
            'hands' => $this->hands,
            'scores' => $this->scores,
            'table_plays' => $this->tablePlays,
            'final_round_number' => $this->finalRoundNumber,
        ];
    }
}
