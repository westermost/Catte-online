<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Play;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_timeout_auto_plays_card_and_advances_turn(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $room = Room::create([
            'code' => 'ABC123',
            'name' => 'Timeout Test',
            'status' => 'playing',
        ]);

        $currentPlayer = Player::create([
            'room_id' => $room->id,
            'session_id' => 'session-current',
            'guest_token' => 'guest-current',
            'name' => 'Current',
            'seat_position' => 0,
            'status' => 'connected',
        ]);

        $nextPlayer = Player::create([
            'room_id' => $room->id,
            'session_id' => 'session-next',
            'guest_token' => 'guest-next',
            'name' => 'Next',
            'seat_position' => 1,
            'status' => 'connected',
        ]);

        $room->update(['owner_player_id' => $currentPlayer->id]);

        $game = Game::create([
            'room_id' => $room->id,
            'game_number' => 1,
            'phase' => 'playing',
            'current_round' => 1,
            'current_player_id' => $currentPlayer->id,
            'hands' => [
                $currentPlayer->id => ['3S', 'AH'],
                $nextPlayer->id => ['4S', 'KH'],
            ],
            'turn_started_at' => now()->subSeconds(31),
            'started_at' => now()->subMinute(),
        ]);

        $round = Round::create([
            'game_id' => $game->id,
            'round_number' => 1,
            'lead_player_id' => $currentPlayer->id,
            'participant_count' => 2,
            'started_at' => now()->subMinute(),
        ]);

        $this->withSession([
            'guest_token' => 'guest-next',
            'guest_name' => 'Next',
        ])->postJson("/api/game/{$game->id}/claim-timeout")
            ->assertOk()
            ->assertJson(['success' => true]);

        $play = Play::where('round_id', $round->id)->first();

        $this->assertNotNull($play);
        $this->assertSame($currentPlayer->id, $play->player_id);
        $this->assertSame('3S', $play->card);
        $this->assertFalse($play->is_face_down);

        $game->refresh();
        $this->assertSame($nextPlayer->id, $game->current_player_id);
        $this->assertSame([
            $currentPlayer->id => ['AH'],
            $nextPlayer->id => ['4S', 'KH'],
        ], $game->hands);

        $currentPlayer->refresh();
        $this->assertSame(1, $currentPlayer->timeout_count);
    }
}
