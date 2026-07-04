<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Play;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\Score;
use App\Services\CatteGameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameFlowTest extends TestCase
{
    use RefreshDatabase;

    private function createRoomWithPlayers(int $playerCount = 2): array
    {
        $room = Room::create([
            'code' => 'TEST' . rand(10, 99),
            'name' => 'Test Room',
            'max_players' => $playerCount,
            'status' => 'playing',
            'thoi_ach_enabled' => false,
        ]);

        $players = [];
        for ($i = 0; $i < $playerCount; $i++) {
            $players[] = Player::create([
                'room_id' => $room->id,
                'session_id' => "session-{$i}",
                'guest_token' => "guest-{$i}",
                'name' => "Player {$i}",
                'seat_position' => $i,
                'status' => 'connected',
            ]);
        }

        $room->update(['owner_player_id' => $players[0]->id]);

        return [$room, $players];
    }

    private function createGameWithRound(Room $room, array $players, array $hands, int $roundNum = 1): array
    {
        $game = Game::create([
            'room_id' => $room->id,
            'game_number' => 1,
            'phase' => $roundNum >= 5 ? 'chung' : 'playing',
            'current_round' => $roundNum,
            'current_player_id' => $players[0]->id,
            'hands' => $hands,
            'turn_started_at' => now(),
            'started_at' => now()->subMinute(),
        ]);

        $round = Round::create([
            'game_id' => $game->id,
            'round_number' => $roundNum,
            'lead_player_id' => $players[0]->id,
            'participant_count' => count($players),
            'started_at' => now(),
        ]);

        return [$game, $round];
    }

    // =========================================================================
    // forceTimeout (cron path)
    // =========================================================================

    public function test_force_timeout_processes_stale_turn(): void
    {
        [$room, $players] = $this->createRoomWithPlayers(2);

        $hands = [
            $players[0]->id => ['3S', '5H'],
            $players[1]->id => ['4S', 'KH'],
        ];

        $game = Game::create([
            'room_id' => $room->id,
            'game_number' => 1,
            'phase' => 'playing',
            'current_round' => 1,
            'current_player_id' => $players[0]->id,
            'hands' => $hands,
            'turn_started_at' => now()->subSeconds(65), // stale > 60s
            'started_at' => now()->subMinutes(2),
        ]);

        Round::create([
            'game_id' => $game->id,
            'round_number' => 1,
            'lead_player_id' => $players[0]->id,
            'participant_count' => 2,
            'started_at' => now()->subMinutes(2),
        ]);

        // Simulate cron calling forceTimeout
        $controller = app(\App\Http\Controllers\GameController::class);
        $controller->forceTimeout($game);

        // Verify a play was made
        $play = Play::where('player_id', $players[0]->id)->first();
        $this->assertNotNull($play);

        // Verify timeout count incremented
        $players[0]->refresh();
        $this->assertSame(1, $players[0]->timeout_count);

        // Verify turn advanced
        $game->refresh();
        $this->assertSame($players[1]->id, $game->current_player_id);
    }

    public function test_force_timeout_skips_if_already_processed(): void
    {
        [$room, $players] = $this->createRoomWithPlayers(2);

        $hands = [
            $players[0]->id => ['3S', '5H'],
            $players[1]->id => ['4S', 'KH'],
        ];

        $game = Game::create([
            'room_id' => $room->id,
            'game_number' => 1,
            'phase' => 'playing',
            'current_round' => 1,
            'current_player_id' => $players[0]->id,
            'hands' => $hands,
            'turn_started_at' => now()->subSeconds(65),
            'started_at' => now()->subMinutes(2),
        ]);

        $round = Round::create([
            'game_id' => $game->id,
            'round_number' => 1,
            'lead_player_id' => $players[0]->id,
            'participant_count' => 2,
            'started_at' => now()->subMinutes(2),
        ]);

        // Pre-insert a play (already processed)
        Play::create([
            'round_id' => $round->id,
            'player_id' => $players[0]->id,
            'card' => '3S',
            'is_face_down' => false,
            'play_order' => 1,
        ]);

        $controller = app(\App\Http\Controllers\GameController::class);
        $controller->forceTimeout($game);

        // Should not create another play
        $this->assertSame(1, Play::where('round_id', $round->id)->count());
    }

    public function test_round_six_auto_reveals_after_round_five(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        [$room, $players] = $this->createRoomWithPlayers(2);

        [$game, $round5] = $this->createGameWithRound($room, $players, [
            $players[0]->id => ['7H', '2D'],
            $players[1]->id => ['8H', 'AS'],
        ], 5);

        $this->withSession([
            'guest_token' => $players[0]->guest_token,
            'guest_name' => $players[0]->name,
        ])->postJson("/api/game/{$game->id}/play", [
            'card' => '7H',
            'face_down' => false,
        ])->assertOk();

        $game->refresh();
        $this->assertSame($players[1]->id, $game->current_player_id);

        $this->withSession([
            'guest_token' => $players[1]->guest_token,
            'guest_name' => $players[1]->name,
        ])->postJson("/api/game/{$game->id}/play", [
            'card' => '8H',
            'face_down' => false,
        ])->assertOk();

        $game->refresh();
        $round6 = Round::where('game_id', $game->id)->where('round_number', 6)->first();

        $this->assertNotNull($round6);
        $this->assertSame('finished', $game->phase);
        $this->assertSame(6, $game->current_round);
        $this->assertSame($players[1]->id, $game->winner_id);
        $this->assertSame(2, Play::where('round_id', $round6->id)->count());
        $this->assertDatabaseHas('plays', [
            'round_id' => $round6->id,
            'player_id' => $players[1]->id,
            'card' => 'AS',
            'is_face_down' => 0,
            'play_order' => 1,
        ]);
        $this->assertDatabaseHas('plays', [
            'round_id' => $round6->id,
            'player_id' => $players[0]->id,
            'card' => '2D',
            'is_face_down' => 0,
            'play_order' => 2,
        ]);
        $this->assertDatabaseHas('scores', [
            'room_id' => $room->id,
            'guest_token' => $players[1]->guest_token,
            'games_won' => 1,
            'games_lost' => 0,
        ]);
        $this->assertDatabaseHas('scores', [
            'room_id' => $room->id,
            'guest_token' => $players[0]->guest_token,
            'games_won' => 0,
            'games_lost' => 1,
        ]);
    }

    // =========================================================================
    // Leave mid-game
    // =========================================================================

    public function test_player_leave_mid_game_marks_as_left(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        [$room, $players] = $this->createRoomWithPlayers(3);

        $response = $this->withSession([
            'guest_token' => 'guest-2',
            'guest_name' => 'Player 2',
        ])->post("/rooms/{$room->code}/leave");

        $players[2]->refresh();
        $this->assertSame('left', $players[2]->status);
    }

    public function test_last_player_leave_deletes_empty_room(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $room = Room::create([
            'code' => 'EMPTY1',
            'name' => 'Empty Room',
            'max_players' => 4,
            'status' => 'waiting',
        ]);

        $player = Player::create([
            'room_id' => $room->id,
            'session_id' => 'session-last',
            'guest_token' => 'guest-last',
            'name' => 'Last Player',
            'seat_position' => 0,
            'status' => 'connected',
        ]);

        $room->update(['owner_player_id' => $player->id]);

        $this->withSession([
            'guest_token' => 'guest-last',
            'guest_name' => 'Last Player',
        ])->post("/rooms/{$room->code}/leave")
            ->assertRedirect('/lobby');

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_player_can_join_released_seat_after_someone_leaves(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $room = Room::create([
            'code' => 'SEAT01',
            'name' => 'Seat Reuse Room',
            'max_players' => 3,
            'status' => 'waiting',
        ]);

        $owner = Player::create([
            'room_id' => $room->id,
            'session_id' => 'session-owner',
            'guest_token' => 'guest-owner',
            'name' => 'Owner',
            'seat_position' => 0,
            'status' => 'connected',
        ]);

        $leavingPlayer = Player::create([
            'room_id' => $room->id,
            'session_id' => 'session-leave',
            'guest_token' => 'guest-leave',
            'name' => 'Leaving Player',
            'seat_position' => 1,
            'status' => 'connected',
        ]);

        $room->update(['owner_player_id' => $owner->id]);

        $this->withSession([
            'guest_token' => 'guest-leave',
            'guest_name' => 'Leaving Player',
        ])->post("/rooms/{$room->code}/leave")
            ->assertRedirect('/lobby');

        $leavingPlayer->refresh();
        $this->assertSame('left', $leavingPlayer->status);
        $this->assertNotSame(1, $leavingPlayer->seat_position);

        $this->withSession([
            'guest_token' => 'guest-new',
            'guest_name' => 'New Player',
        ])->post("/rooms/{$room->code}/join")
            ->assertRedirect("/rooms/{$room->code}");

        $this->assertDatabaseHas('players', [
            'room_id' => $room->id,
            'guest_token' => 'guest-new',
            'seat_position' => 1,
            'status' => 'connected',
        ]);
    }

    public function test_lobby_cleanup_deletes_rooms_with_no_occupants(): void
    {
        $room = Room::create([
            'code' => 'EMPTY2',
            'name' => 'Old Empty Room',
            'max_players' => 4,
            'status' => 'waiting',
        ]);

        Player::create([
            'room_id' => $room->id,
            'session_id' => 'session-left',
            'guest_token' => 'guest-left',
            'name' => 'Left Player',
            'seat_position' => 0,
            'status' => 'left',
        ]);

        $this->withSession([
            'guest_token' => 'guest-viewer',
            'guest_name' => 'Viewer',
        ])->get('/lobby')
            ->assertOk();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_lobby_cleanup_deletes_waiting_room_with_only_stale_players(): void
    {
        $room = Room::create([
            'code' => 'STALE1',
            'name' => 'Stale Room',
            'max_players' => 4,
            'status' => 'waiting',
        ]);

        $player = Player::create([
            'room_id' => $room->id,
            'session_id' => 'session-stale',
            'guest_token' => 'guest-stale',
            'name' => 'Stale Player',
            'seat_position' => 0,
            'status' => 'connected',
            'last_active_at' => now()->subSeconds(Room::WAITING_ROOM_STALE_SECONDS + 5),
        ]);

        $room->update(['owner_player_id' => $player->id]);

        $this->withSession([
            'guest_token' => 'guest-viewer',
            'guest_name' => 'Viewer',
        ])->get('/lobby')
            ->assertOk();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_room_state_refresh_keeps_waiting_room_player_active(): void
    {
        $room = Room::create([
            'code' => 'LIVE01',
            'name' => 'Live Room',
            'max_players' => 4,
            'status' => 'waiting',
        ]);

        $player = Player::create([
            'room_id' => $room->id,
            'session_id' => 'session-live',
            'guest_token' => 'guest-live',
            'name' => 'Live Player',
            'seat_position' => 0,
            'status' => 'connected',
            'last_active_at' => now()->subSeconds(Room::WAITING_ROOM_STALE_SECONDS - 5),
        ]);

        $room->update(['owner_player_id' => $player->id]);

        $this->withSession([
            'guest_token' => 'guest-live',
            'guest_name' => 'Live Player',
        ])->getJson("/api/rooms/{$room->id}/state")
            ->assertOk();

        $player->refresh();
        $this->assertSame('connected', $player->status);
        $this->assertTrue($player->last_active_at->greaterThan(now()->subSeconds(5)));
    }

    public function test_all_players_ready_auto_starts_next_game_with_previous_winner_leading(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        [$room, $players] = $this->createRoomWithPlayers(2);
        $room->update([
            'status' => 'waiting',
            'next_game_deadline_at' => now()->addSeconds(Room::NEXT_GAME_READY_TIMEOUT_SECONDS),
        ]);

        Game::create([
            'room_id' => $room->id,
            'game_number' => 1,
            'phase' => 'finished',
            'current_round' => 6,
            'hands' => [],
            'winner_id' => $players[1]->id,
            'started_at' => now()->subMinute(),
        ]);

        $this->withSession([
            'guest_token' => $players[0]->guest_token,
            'guest_name' => $players[0]->name,
        ])->postJson("/rooms/{$room->code}/ready-next")
            ->assertOk();

        $this->withSession([
            'guest_token' => $players[1]->guest_token,
            'guest_name' => $players[1]->name,
        ])->postJson("/rooms/{$room->code}/ready-next")
            ->assertOk();

        $room->refresh();
        $newGame = Game::where('room_id', $room->id)->where('phase', '!=', 'finished')->latest('game_number')->first();

        $this->assertNotNull($newGame);
        $this->assertSame('playing', $newGame->phase);
        $this->assertSame($players[1]->id, $newGame->current_player_id);
        $this->assertNull($room->next_game_deadline_at);
    }

    public function test_next_game_deadline_removes_players_who_do_not_ready(): void
    {
        $room = Room::create([
            'code' => 'READY1',
            'name' => 'Ready Room',
            'max_players' => 3,
            'status' => 'waiting',
            'next_game_deadline_at' => now()->subSecond(),
        ]);

        $readyPlayer = Player::create([
            'room_id' => $room->id,
            'session_id' => 'session-ready',
            'guest_token' => 'guest-ready',
            'name' => 'Ready Player',
            'seat_position' => 0,
            'status' => 'connected',
            'ready_for_next_game' => true,
        ]);

        $latePlayer = Player::create([
            'room_id' => $room->id,
            'session_id' => 'session-late',
            'guest_token' => 'guest-late',
            'name' => 'Late Player',
            'seat_position' => 1,
            'status' => 'connected',
            'ready_for_next_game' => false,
        ]);

        $room->update(['owner_player_id' => $readyPlayer->id]);

        $this->withSession([
            'guest_token' => $readyPlayer->guest_token,
            'guest_name' => $readyPlayer->name,
        ])->getJson("/api/rooms/{$room->id}/state")
            ->assertOk();

        $latePlayer->refresh();
        $readyPlayer->refresh();
        $room->refresh();

        $this->assertSame('left', $latePlayer->status);
        $this->assertSame('connected', $readyPlayer->status);
        $this->assertFalse($readyPlayer->ready_for_next_game);
        $this->assertNull($room->next_game_deadline_at);
    }

    // =========================================================================
    // Scoring
    // =========================================================================

    public function test_calculate_scores_normal_win(): void
    {
        $engine = app(CatteGameEngine::class);

        $winnerId = 1;
        $eliminated = [2, 3];
        $hands = [
            1 => [],
            2 => ['AH', '3S'], // has ace
            3 => ['4D', '5C'],
        ];

        $scores = $engine->calculateScores($winnerId, 'normal', $eliminated, $hands, false);

        $this->assertSame(1, $scores[1]); // winner +1
        $this->assertSame(-1, $scores[2]); // eliminated -1
        $this->assertSame(-1, $scores[3]); // eliminated -1
    }

    public function test_calculate_scores_thoi_ach_enabled(): void
    {
        $engine = app(CatteGameEngine::class);

        $winnerId = 1;
        $eliminated = [2];
        $hands = [
            1 => [],
            2 => ['AH', 'AS', '3S'], // 2 aces remaining
        ];

        $scores = $engine->calculateScores($winnerId, 'normal', $eliminated, $hands, true);

        $this->assertSame(1, $scores[1]); // winner +1
        $this->assertSame(-3, $scores[2]); // eliminated -1, thối ách -2
    }

    public function test_calculate_scores_thang_tung(): void
    {
        $engine = app(CatteGameEngine::class);

        $winnerId = 1;
        $eliminated = [2, 3];
        $hands = [
            1 => [],
            2 => ['4D'],
            3 => ['5C'],
        ];

        $scores = $engine->calculateScores($winnerId, 'thang_tung', $eliminated, $hands, false);

        $this->assertSame(2, $scores[1]); // thắng tùng +2
    }

    public function test_calculate_scores_instant_win(): void
    {
        $engine = app(CatteGameEngine::class);

        $winnerId = 1;
        $hands = [
            1 => ['AH', 'AS', 'AD', 'AC', '3H', '4H'],
            2 => ['KH', 'QH', 'JH', '10H', '9H', '8H'],
        ];

        $scores = $engine->calculateScores($winnerId, 'instant_win', [], $hands, false);

        $this->assertSame(2, $scores[1]); // instant win +2
    }

    // =========================================================================
    // Post-round-4 evaluation
    // =========================================================================

    public function test_evaluate_post_round4_guc_tung(): void
    {
        $engine = app(CatteGameEngine::class);

        // Player 1 won rounds 1,2,3,4. Player 2 won nothing.
        $roundWinners = [1, 1, 1, 1];
        $activePlayers = [1, 2, 3];

        $result = $engine->evaluatePostRound4($roundWinners, $activePlayers);

        // Players 2 and 3 should be eliminated (no tồn)
        $this->assertContains(2, $result['eliminated']);
        $this->assertContains(3, $result['eliminated']);
        // Player 1 thắng tùng
        $this->assertFalse($result['continue']);
        $this->assertSame(1, $result['winner']);
    }

    public function test_evaluate_post_round4_continue_to_chung(): void
    {
        $engine = app(CatteGameEngine::class);

        // Players 1 and 2 each won 2 rounds. Player 3 won nothing.
        $roundWinners = [1, 2, 1, 2];
        $activePlayers = [1, 2, 3];

        $result = $engine->evaluatePostRound4($roundWinners, $activePlayers);

        $this->assertContains(3, $result['eliminated']);
        $this->assertTrue($result['continue']);
        $this->assertNull($result['winner']);
    }

    // =========================================================================
    // Kick after 2 consecutive timeouts
    // =========================================================================

    public function test_second_timeout_kicks_player(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        [$room, $players] = $this->createRoomWithPlayers(3);

        // Set player 0 already at 1 timeout
        $players[0]->update(['timeout_count' => 1]);

        $hands = [
            $players[0]->id => ['3S', '5H'],
            $players[1]->id => ['4S', 'KH'],
            $players[2]->id => ['7D', '9C'],
        ];

        $game = Game::create([
            'room_id' => $room->id,
            'game_number' => 1,
            'phase' => 'playing',
            'current_round' => 1,
            'current_player_id' => $players[0]->id,
            'hands' => $hands,
            'turn_started_at' => now()->subSeconds(35),
            'started_at' => now()->subMinutes(2),
        ]);

        Round::create([
            'game_id' => $game->id,
            'round_number' => 1,
            'lead_player_id' => $players[0]->id,
            'participant_count' => 3,
            'started_at' => now()->subMinutes(2),
        ]);

        $this->withSession([
            'guest_token' => 'guest-1',
            'guest_name' => 'Player 1',
        ])->postJson("/api/game/{$game->id}/claim-timeout")
            ->assertOk();

        $players[0]->refresh();
        $this->assertSame('kicked', $players[0]->status);
        $this->assertSame(2, $players[0]->timeout_count);
    }
}
