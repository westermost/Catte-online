<?php

namespace App\Http\Controllers;

use App\Events\GameStarting;
use App\Models\Game;
use App\Models\Play;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\Score;
use App\Services\CatteGameEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameController extends Controller
{
    public function __construct(
        private CatteGameEngine $engine
    ) {}

    /**
     * Owner starts a new game.
     */
    public function start(Room $room)
    {
        $guestToken = session('guest_token');
        $player = $room->players()->where('guest_token', $guestToken)->first();

        if (!$player || $room->owner_player_id !== $player->id) {
            return back()->withErrors(['game' => 'Chỉ chủ phòng mới được bắt đầu.']);
        }

        $activePlayers = $room->players()
            ->where('status', 'connected')
            ->orderBy('seat_position')
            ->get();

        if ($activePlayers->count() < 2) {
            return back()->withErrors(['game' => 'Cần ít nhất 2 người chơi.']);
        }

        // Determine game number
        $lastGame = Game::where('room_id', $room->id)->max('game_number');
        $gameNumber = ($lastGame ?? 0) + 1;

        // Deal cards
        $playerIds = $activePlayers->pluck('id')->toArray();
        $hands = $this->engine->dealCards($playerIds);

        // Determine who leads round 1
        $leadPlayerId = $this->getFirstLeader($room, $activePlayers);

        // Create game
        $game = Game::create([
            'room_id' => $room->id,
            'game_number' => $gameNumber,
            'phase' => 'instant_win_check',
            'current_round' => 1,
            'current_player_id' => $leadPlayerId,
            'hands' => $hands,
            'started_at' => now(),
        ]);

        // Check instant win
        $instantWin = $this->engine->checkInstantWin($hands);

        if ($instantWin) {
            $game->update([
                'phase' => 'finished',
                'instant_winner_id' => $instantWin['winner_id'],
                'instant_win_type' => $instantWin['type'],
                'winner_id' => $instantWin['winner_id'],
            ]);

            // Calculate scores
            $scores = $this->engine->calculateScores(
                $instantWin['winner_id'],
                'instant_win',
                [],
                $hands,
                $room->thoi_ach_enabled
            );
            $this->updateScores($room, $scores, $activePlayers, $instantWin['winner_id']);

            $room->update([
                'status' => 'waiting',
                'next_game_deadline_at' => now()->addSeconds(Room::NEXT_GAME_READY_TIMEOUT_SECONDS),
            ]);

            Player::where('room_id', $room->id)
                ->whereIn('status', ['connected', 'disconnected'])
                ->update(['ready_for_next_game' => false]);

            broadcast(new \App\Events\GameEnded(
                $room->id,
                $game->id,
                $instantWin['winner_id'],
                'instant_win',
                $instantWin['type'],
                $hands,
                $scores
            ));
        } else {
            // Move to playing phase, create first round
            $game->update([
                'phase' => 'playing',
                'turn_started_at' => now(),
            ]);

            Round::create([
                'game_id' => $game->id,
                'round_number' => 1,
                'lead_player_id' => $leadPlayerId,
                'participant_count' => count($playerIds),
                'started_at' => now(),
            ]);

            $room->update(['status' => 'playing']);

            broadcast(new GameStarting($room->id, $game->id));
            broadcast(new \App\Events\TurnStarted($room->id, $game->id, $leadPlayerId, now()));

            // Send hands to each player privately
            foreach ($activePlayers as $p) {
                broadcast(new \App\Events\YourHand($p->id, $hands[$p->id]));
            }
        }

        // Reset timeout/ready state for all players entering the next game.
        $activePlayers->each(fn ($p) => $p->update([
            'timeout_count' => 0,
            'ready_for_next_game' => false,
        ]));
        $room->update(['next_game_deadline_at' => null]);

        return back();
    }

    public function startReadyPlayersGame(Room $room): bool
    {
        $activePlayers = $room->players()
            ->where('status', 'connected')
            ->where('ready_for_next_game', true)
            ->orderBy('seat_position')
            ->get();

        if ($activePlayers->count() < 2) {
            return false;
        }

        $lastGame = Game::where('room_id', $room->id)->max('game_number');
        $gameNumber = ($lastGame ?? 0) + 1;

        $playerIds = $activePlayers->pluck('id')->toArray();
        $hands = $this->engine->dealCards($playerIds);
        $leadPlayerId = $this->getFirstLeader($room, $activePlayers);

        $game = Game::create([
            'room_id' => $room->id,
            'game_number' => $gameNumber,
            'phase' => 'instant_win_check',
            'current_round' => 1,
            'current_player_id' => $leadPlayerId,
            'hands' => $hands,
            'started_at' => now(),
        ]);

        $instantWin = $this->engine->checkInstantWin($hands);

        if ($instantWin) {
            $game->update([
                'phase' => 'finished',
                'instant_winner_id' => $instantWin['winner_id'],
                'instant_win_type' => $instantWin['type'],
                'winner_id' => $instantWin['winner_id'],
            ]);

            $scores = $this->engine->calculateScores(
                $instantWin['winner_id'],
                'instant_win',
                [],
                $hands,
                $room->thoi_ach_enabled
            );
            $this->updateScores($room, $scores, $activePlayers, $instantWin['winner_id']);

            $room->update([
                'status' => 'waiting',
                'next_game_deadline_at' => now()->addSeconds(Room::NEXT_GAME_READY_TIMEOUT_SECONDS),
            ]);

            Player::where('room_id', $room->id)
                ->whereIn('status', ['connected', 'disconnected'])
                ->update(['ready_for_next_game' => false]);

            broadcast(new \App\Events\GameEnded(
                $room->id,
                $game->id,
                $instantWin['winner_id'],
                'instant_win',
                $instantWin['type'],
                $hands,
                $scores
            ));
        } else {
            $game->update([
                'phase' => 'playing',
                'turn_started_at' => now(),
            ]);

            Round::create([
                'game_id' => $game->id,
                'round_number' => 1,
                'lead_player_id' => $leadPlayerId,
                'participant_count' => count($playerIds),
                'started_at' => now(),
            ]);

            $room->update([
                'status' => 'playing',
                'next_game_deadline_at' => null,
            ]);

            broadcast(new GameStarting($room->id, $game->id));
            broadcast(new \App\Events\TurnStarted($room->id, $game->id, $leadPlayerId, now()));

            foreach ($activePlayers as $p) {
                broadcast(new \App\Events\YourHand($p->id, $hands[$p->id]));
            }
        }

        $activePlayers->each(fn ($p) => $p->update([
            'timeout_count' => 0,
            'ready_for_next_game' => false,
        ]));

        return true;
    }

    /**
     * Player plays a card.
     */
    public function playCard(Request $request, Game $game)
    {
        $request->validate([
            'card' => 'required|string|max:3',
            'face_down' => 'required|boolean',
        ]);

        $guestToken = session('guest_token');
        $player = Player::where('guest_token', $guestToken)
            ->where('room_id', $game->room_id)
            ->first();

        if (!$player) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!in_array($player->status, ['connected', 'disconnected'])) {
            return response()->json(['error' => 'Bạn chỉ được xem ván này.'], 403);
        }

        if ($game->current_player_id !== $player->id) {
            return response()->json(['error' => 'Chưa đến lượt bạn.'], 422);
        }

        if ($game->phase === 'finished') {
            return response()->json(['error' => 'Ván đã kết thúc.'], 422);
        }

        if ($game->turn_started_at && $game->turn_started_at->diffInSeconds(now()) >= 30) {
            return $this->claimTimeout($request, $game);
        }

        $hands = $game->hands;
        $playerHand = $hands[$player->id] ?? [];
        $card = $request->card;
        $faceDown = $request->boolean('face_down');

        // Get current round
        $round = $game->rounds()->where('round_number', $game->current_round)->first();
        if (!$round) {
            return response()->json(['error' => 'Round not found.'], 500);
        }

        $existingPlays = $round->plays()->get()->toArray();
        $isLeadPlay = empty($existingPlays);
        $isChungRound = $game->current_round >= 5;
        $leadSuit = $round->lead_suit;
        $currentWinningCard = null;

        if (!$isLeadPlay) {
            $currentWinningCard = $this->engine->getCurrentWinningCard(
                array_map(fn ($p) => ['card' => $p['card'], 'is_face_down' => $p['is_face_down'], 'player_id' => $p['player_id']], $existingPlays),
                $leadSuit
            );
        }

        // Validate play
        if (!$this->engine->validatePlay($card, $faceDown, $playerHand, $leadSuit, $currentWinningCard, $isLeadPlay, $isChungRound)) {
            return response()->json(['error' => 'Không thể đánh lá này.'], 422);
        }

        return DB::transaction(function () use ($game, $round, $player, $card, $faceDown, $hands, $isLeadPlay, $existingPlays) {
            // Record play
            Play::create([
                'round_id' => $round->id,
                'player_id' => $player->id,
                'card' => $card,
                'is_face_down' => $faceDown,
                'play_order' => count($existingPlays) + 1,
                'created_at' => now(),
            ]);

            // Set lead suit if this is the lead play
            if ($isLeadPlay) {
                $parsed = $this->engine->parseCard($card);
                $round->update(['lead_suit' => $parsed['suit']]);
            }

            // Remove card from hand
            $hands = $this->engine->removeCardFromHand($hands, $player->id, $card);
            $game->update(['hands' => $hands]);

            // Reset timeout
            $player->update(['timeout_count' => 0, 'last_active_at' => now()]);

            // Broadcast to every client, including the actor, so all tables use the same event stream.
            broadcast(new \App\Events\CardPlayed(
                $game->room_id,
                $game->id,
                $player->id,
                $faceDown ? null : $card,
                $faceDown,
                $game->current_round,
                count($existingPlays) + 1,
            ));

            // Check if round is complete (use persisted participant_count from round start)
            $activePlayers = $this->getActivePlayersForGame($game);
            $totalPlays = count($existingPlays) + 1;

            if ($totalPlays >= $round->participant_count) {
                $this->completeRound($game, $round, $activePlayers, $hands);
            } else {
                // Next player's turn
                $seatPositions = $this->getSeatPositions($game->room_id, $activePlayers);
                $nextPlayer = $this->engine->getNextPlayer($player->id, $activePlayers, $seatPositions);

                $game->update([
                    'current_player_id' => $nextPlayer,
                    'turn_started_at' => now(),
                ]);

                broadcast(new \App\Events\TurnStarted($game->room_id, $game->id, $nextPlayer, now()));
            }

            return response()->json([
                'success' => true,
                'hand' => $hands[$player->id] ?? [],
                'card_played' => $card,
                'face_down' => $faceDown,
            ]);
        });
    }

    /**
     * Any client can claim timeout when a player exceeds 30s.
     */
    public function claimTimeout(Request $request, Game $game)
    {
        $guestToken = session('guest_token');
        $requester = Player::where('guest_token', $guestToken)
            ->where('room_id', $game->room_id)
            ->first();

        if (!$requester) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($game->phase === 'finished') {
            return response()->json(['error' => 'Game ended'], 422);
        }

        return DB::transaction(function () use ($game) {
            // Re-fetch with lock
            $game = Game::lockForUpdate()->find($game->id);

            if (!$game || $game->phase === 'finished') {
                return response()->json(['error' => 'Game ended'], 422);
            }

            if (!$game->turn_started_at) {
                return response()->json(['error' => 'No active turn'], 422);
            }

            $elapsed = $game->turn_started_at->diffInSeconds(now());

            // Accept a small early grace because client countdowns and server
            // timestamps can differ by sub-second rounding/latency.
            if ($elapsed < 29) {
                return response()->json(['error' => 'Too early'], 422);
            }

            // Check if already processed (player already played this round)
            $round = $game->rounds()->where('round_number', $game->current_round)->first();
            if (!$round) {
                return response()->json(['error' => 'Round not found'], 422);
            }

            $alreadyPlayed = Play::where('round_id', $round->id)
                ->where('player_id', $game->current_player_id)
                ->exists();

            if ($alreadyPlayed) {
                return response()->json(['error' => 'Already processed'], 409);
            }

            // Auto-play
            $hands = $game->hands;
            $playerHand = $hands[$game->current_player_id] ?? [];
            if (empty($playerHand)) {
                return $this->advanceAfterEmptyTimeoutHand($game, $round);
            }
            $autoResult = $this->engine->autoPlay($playerHand, $round->lead_suit);
            if (empty($autoResult['card'])) {
                return $this->advanceAfterEmptyTimeoutHand($game, $round);
            }

            // Execute the auto-play
            $existingPlays = $round->plays()->get()->toArray();
            $isLeadPlay = empty($existingPlays);

            Play::create([
                'round_id' => $round->id,
                'player_id' => $game->current_player_id,
                'card' => $autoResult['card'],
                'is_face_down' => $autoResult['face_down'],
                'play_order' => count($existingPlays) + 1,
                'created_at' => now(),
            ]);

            if ($isLeadPlay) {
                $parsed = $this->engine->parseCard($autoResult['card']);
                $round->update(['lead_suit' => $parsed['suit']]);
            }

            $hands = $this->engine->removeCardFromHand($hands, $game->current_player_id, $autoResult['card']);
            $game->update(['hands' => $hands]);

            // Increment timeout
            $timeoutPlayer = Player::find($game->current_player_id);
            $timeoutPlayer->increment('timeout_count');
            broadcast(new \App\Events\YourHand($timeoutPlayer->id, $hands[$timeoutPlayer->id] ?? []));

            // Broadcast timeout
            broadcast(new \App\Events\TurnTimeout($game->room_id, $game->id, $game->current_player_id));
            broadcast(new \App\Events\CardPlayed(
                $game->room_id,
                $game->id,
                $game->current_player_id,
                $autoResult['face_down'] ? null : $autoResult['card'],
                $autoResult['face_down'],
                $game->current_round,
                count($existingPlays) + 1,
            ));

            // Compute next player BEFORE potentially kicking current player
            $activePlayers = $this->getActivePlayersForGame($game);
            $seatPositions = $this->getSeatPositions($game->room_id, $activePlayers);
            $nextPlayer = $this->engine->getNextPlayer($game->current_player_id, $activePlayers, $seatPositions);

            // Check if player should be kicked (2 consecutive timeouts)
            if ($timeoutPlayer->timeout_count >= 2) {
                $timeoutPlayer->update(['status' => 'kicked']);
                broadcast(new \App\Events\PlayerKicked($game->room_id, $timeoutPlayer->id, $timeoutPlayer->name));
                // Refresh active players after kick (for next round)
                $activePlayers = $this->getActivePlayersForGame($game);
            }

            // Check round completion - use persisted participant_count from round start
            $totalPlays = count($existingPlays) + 1;

            if ($totalPlays >= $round->participant_count) {
                $this->completeRound($game, $round, $activePlayers, $hands);
            } else {
                if (count($activePlayers) <= 1) {
                    $this->finishByRemainingPlayer($game, $hands);
                    return response()->json(['success' => true]);
                }

                if (!$nextPlayer || !in_array($nextPlayer, $activePlayers)) {
                    $seatPositions = $this->getSeatPositions($game->room_id, $activePlayers);
                    $nextPlayer = $this->engine->getNextPlayer($game->current_player_id, $activePlayers, $seatPositions);
                }

                if (!$nextPlayer) {
                    $this->finishByRemainingPlayer($game, $hands);
                    return response()->json(['success' => true]);
                }

                $game->update([
                    'current_player_id' => $nextPlayer,
                    'turn_started_at' => now(),
                ]);
                broadcast(new \App\Events\TurnStarted($game->room_id, $game->id, $nextPlayer, now()));
            }

            return response()->json(['success' => true]);
        });
    }

    /**
     * Force timeout for stale turns (called by cron scheduler).
     * Same logic as claimTimeout but without requester validation.
     */
    public function forceTimeout(Game $game): void
    {
        DB::transaction(function () use ($game) {
            $game = Game::lockForUpdate()->find($game->id);

            if (!$game || $game->phase === 'finished' || !$game->turn_started_at) {
                return;
            }

            $elapsed = $game->turn_started_at->diffInSeconds(now());
            if ($elapsed < 60) {
                return;
            }

            $round = $game->rounds()->where('round_number', $game->current_round)->first();
            if (!$round) {
                return;
            }

            $alreadyPlayed = Play::where('round_id', $round->id)
                ->where('player_id', $game->current_player_id)
                ->exists();

            if ($alreadyPlayed) {
                return;
            }

            $hands = $game->hands;
            $playerHand = $hands[$game->current_player_id] ?? [];

            if (empty($playerHand)) {
                $this->advanceAfterEmptyTimeoutHand($game, $round);
                return;
            }

            $autoResult = $this->engine->autoPlay($playerHand, $round->lead_suit);
            if (empty($autoResult['card'])) {
                $this->advanceAfterEmptyTimeoutHand($game, $round);
                return;
            }

            $existingPlays = $round->plays()->get()->toArray();
            $isLeadPlay = empty($existingPlays);

            Play::create([
                'round_id' => $round->id,
                'player_id' => $game->current_player_id,
                'card' => $autoResult['card'],
                'is_face_down' => $autoResult['face_down'],
                'play_order' => count($existingPlays) + 1,
                'created_at' => now(),
            ]);

            if ($isLeadPlay) {
                $parsed = $this->engine->parseCard($autoResult['card']);
                $round->update(['lead_suit' => $parsed['suit']]);
            }

            $hands = $this->engine->removeCardFromHand($hands, $game->current_player_id, $autoResult['card']);
            $game->update(['hands' => $hands]);

            $timeoutPlayer = Player::find($game->current_player_id);
            $timeoutPlayer->increment('timeout_count');
            broadcast(new \App\Events\YourHand($timeoutPlayer->id, $hands[$timeoutPlayer->id] ?? []));

            broadcast(new \App\Events\TurnTimeout($game->room_id, $game->id, $game->current_player_id));
            broadcast(new \App\Events\CardPlayed(
                $game->room_id,
                $game->id,
                $game->current_player_id,
                $autoResult['face_down'] ? null : $autoResult['card'],
                $autoResult['face_down'],
                $game->current_round,
                count($existingPlays) + 1,
            ));

            // Compute next player BEFORE potentially kicking current player
            $activePlayers = $this->getActivePlayersForGame($game);
            $seatPositions = $this->getSeatPositions($game->room_id, $activePlayers);
            $nextPlayer = $this->engine->getNextPlayer($game->current_player_id, $activePlayers, $seatPositions);

            if ($timeoutPlayer->timeout_count >= 2) {
                $timeoutPlayer->update(['status' => 'kicked']);
                broadcast(new \App\Events\PlayerKicked($game->room_id, $timeoutPlayer->id, $timeoutPlayer->name));
                $activePlayers = $this->getActivePlayersForGame($game);
            }

            // Use persisted participant_count from round start for completion check
            $totalPlays = count($existingPlays) + 1;

            if ($totalPlays >= $round->participant_count) {
                $this->completeRound($game, $round, $activePlayers, $hands);
            } else {
                if (count($activePlayers) <= 1) {
                    $this->finishByRemainingPlayer($game, $hands);
                    return;
                }

                if (!$nextPlayer || !in_array($nextPlayer, $activePlayers)) {
                    $seatPositions = $this->getSeatPositions($game->room_id, $activePlayers);
                    $nextPlayer = $this->engine->getNextPlayer($game->current_player_id, $activePlayers, $seatPositions);
                }

                if (!$nextPlayer) {
                    $this->finishByRemainingPlayer($game, $hands);
                    return;
                }

                $game->update([
                    'current_player_id' => $nextPlayer,
                    'turn_started_at' => now(),
                ]);
                broadcast(new \App\Events\TurnStarted($game->room_id, $game->id, $nextPlayer, now()));
            }
        });
    }

    /**
     * Get current player's hand.
     */
    public function getMyHand(Game $game)
    {
        $guestToken = session('guest_token');
        $player = Player::where('guest_token', $guestToken)
            ->where('room_id', $game->room_id)
            ->first();

        if (!$player) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!in_array($player->status, ['connected', 'disconnected'])) {
            return response()->json([
                'hand' => [],
                'game_id' => $game->id,
                'phase' => $game->phase,
                'current_round' => $game->current_round,
                'current_player_id' => $game->current_player_id,
                'turn_started_at' => $game->turn_started_at?->toIso8601String(),
                'is_my_turn' => false,
                'is_spectator' => true,
                'spectator_reason' => $player->status === 'eliminated'
                    ? 'Bạn không vào vòng 5. Bài đã được thu lại, bạn chỉ xem phần chưng.'
                    : 'Bạn chỉ được xem ván này.',
                'table_rounds' => $this->getTableRoundsForClient($game),
            ]);
        }

        $hands = $game->hands;
        return response()->json([
            'hand' => $hands[$player->id] ?? [],
            'game_id' => $game->id,
            'phase' => $game->phase,
            'current_round' => $game->current_round,
            'current_player_id' => $game->current_player_id,
            'turn_started_at' => $game->turn_started_at?->toIso8601String(),
            'is_my_turn' => $game->current_player_id === $player->id,
            'is_spectator' => false,
            'table_rounds' => $this->getTableRoundsForClient($game),
        ]);
    }

    /**
     * Remove a player from the active turn order when they leave mid-game.
     */
    public function handlePlayerLeftDuringGame(Game $game, Player $player, bool $wasActiveParticipant = true): void
    {
        DB::transaction(function () use ($game, $player, $wasActiveParticipant) {
            $game = Game::lockForUpdate()->find($game->id);

            if (!$game || $game->phase === 'finished') {
                return;
            }

            $hands = $game->hands;
            if (array_key_exists($player->id, $hands)) {
                unset($hands[$player->id]);
                $game->update(['hands' => $hands]);
            }

            if (!$wasActiveParticipant) {
                return;
            }

            $activePlayers = $this->getActivePlayersForGame($game);

            if (count($activePlayers) === 0) {
                $game->update([
                    'phase' => 'finished',
                    'current_player_id' => null,
                    'winner_id' => null,
                ]);
                Room::where('id', $game->room_id)->update(['status' => 'finished']);
                return;
            }

            if (count($activePlayers) === 1) {
                $eliminatedIds = Player::where('room_id', $game->room_id)
                    ->where('status', 'eliminated')
                    ->pluck('id')
                    ->toArray();

                $this->endGame($game, $activePlayers[0], 'normal', $eliminatedIds, $hands);
                return;
            }

            $round = $game->rounds()
                ->where('round_number', $game->current_round)
                ->lockForUpdate()
                ->first();

            if (!$round || $round->completed_at) {
                return;
            }

            $hasPlayed = Play::where('round_id', $round->id)
                ->where('player_id', $player->id)
                ->exists();
            $totalPlays = Play::where('round_id', $round->id)->count();

            if (!$hasPlayed && $round->participant_count > $totalPlays) {
                $round->update([
                    'participant_count' => max($totalPlays, $round->participant_count - 1),
                ]);
                $round->refresh();
            }

            if ($totalPlays > 0 && $totalPlays >= $round->participant_count) {
                $this->completeRound($game, $round, $activePlayers, $hands);
                return;
            }

            if ((int) $game->current_player_id === (int) $player->id) {
                $seatPositions = $this->getSeatPositions($game->room_id, $activePlayers);
                $nextPlayer = $this->getNextPlayerAfterSeat($player->seat_position, $activePlayers, $seatPositions);

                if (!$nextPlayer) {
                    return;
                }

                if ($totalPlays === 0) {
                    $round->update(['lead_player_id' => $nextPlayer]);
                }

                $game->update([
                    'current_player_id' => $nextPlayer,
                    'turn_started_at' => now(),
                ]);

                broadcast(new \App\Events\TurnStarted($game->room_id, $game->id, $nextPlayer, now()));
            }
        });
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function advanceAfterEmptyTimeoutHand(Game $game, Round $round)
    {
        $hands = $game->hands;
        $currentPlayerId = $game->current_player_id;

        broadcast(new \App\Events\TurnTimeout($game->room_id, $game->id, $currentPlayerId));

        $timeoutPlayer = Player::find($currentPlayerId);
        if ($timeoutPlayer) {
            $timeoutPlayer->increment('timeout_count');
            if ($timeoutPlayer->timeout_count >= 2) {
                $timeoutPlayer->update(['status' => 'kicked']);
                broadcast(new \App\Events\PlayerKicked($game->room_id, $timeoutPlayer->id, $timeoutPlayer->name));
            }
        }

        $totalPlays = Play::where('round_id', $round->id)->count();
        $alreadyPlayed = Play::where('round_id', $round->id)
            ->where('player_id', $currentPlayerId)
            ->exists();

        if (!$alreadyPlayed && $round->participant_count > $totalPlays) {
            $round->update([
                'participant_count' => max($totalPlays, $round->participant_count - 1),
            ]);
            $round->refresh();
        }

        $activePlayers = $this->getActivePlayersForGame($game);

        if ($totalPlays > 0 && $totalPlays >= $round->participant_count) {
            $this->completeRound($game, $round, $activePlayers, $hands);
            return response()->json(['success' => true]);
        }

        if (count($activePlayers) <= 1) {
            $this->finishByRemainingPlayer($game, $hands);
            return response()->json(['success' => true]);
        }

        $seatPositions = $this->getSeatPositions($game->room_id, $activePlayers);
        $nextPlayer = $this->engine->getNextPlayer($currentPlayerId, $activePlayers, $seatPositions)
            ?? $activePlayers[0];

        $game->update([
            'current_player_id' => $nextPlayer,
            'turn_started_at' => now(),
        ]);

        broadcast(new \App\Events\TurnStarted($game->room_id, $game->id, $nextPlayer, now()));

        return response()->json(['success' => true]);
    }

    private function finishByRemainingPlayer(Game $game, array $hands): void
    {
        $activePlayers = $this->getActivePlayersForGame($game);

        if (count($activePlayers) === 1) {
            $eliminatedIds = Player::where('room_id', $game->room_id)
                ->where('status', 'eliminated')
                ->pluck('id')
                ->toArray();

            $this->endGame($game, $activePlayers[0], 'normal', $eliminatedIds, $hands);
            return;
        }

        $game->update([
            'phase' => 'finished',
            'current_player_id' => null,
        ]);

        Room::where('id', $game->room_id)->update(['status' => 'waiting']);
    }

    private function getTableRoundsForClient(Game $game): array
    {
        return $game->rounds()
            ->with('plays')
            ->orderBy('round_number')
            ->get()
            ->map(function (Round $round) {
                $revealFaceDown = $round->completed_at !== null && $round->round_number >= 5;
                $plays = $round->completed_at
                    ? $round->plays->where('player_id', $round->winner_id)->values()
                    : $round->plays;

                return [
                    'round_number' => $round->round_number,
                    'plays' => $plays->map(fn (Play $play) => [
                        'player_id' => $play->player_id,
                        'card' => ($play->is_face_down && !$revealFaceDown) ? null : $play->card,
                        'is_face_down' => $revealFaceDown ? false : $play->is_face_down,
                        'play_order' => $play->play_order,
                    ])->toArray(),
                ];
            })
            ->filter(fn (array $round) => !empty($round['plays']))
            ->values()
            ->toArray();
    }

    private function completeRound(Game $game, Round $round, array $activePlayers, array $hands): void
    {
        $plays = $round->plays()->get()->map(fn ($p) => [
            'player_id' => $p->player_id,
            'card' => $p->card,
            'is_face_down' => $p->is_face_down,
            'play_order' => $p->play_order,
        ])->toArray();

        $winnerId = $this->engine->evaluateRound($plays, $round->lead_suit, $game->current_round >= 5);
        $round->update(['winner_id' => $winnerId, 'completed_at' => now()]);

        $broadcastPlays = array_map(function (array $play) use ($game) {
            $revealFaceDown = $game->current_round >= 5;

            return [
                'player_id' => $play['player_id'],
                'card' => ($play['is_face_down'] && !$revealFaceDown) ? null : $play['card'],
                'is_face_down' => $revealFaceDown ? false : $play['is_face_down'],
                'play_order' => $play['play_order'],
            ];
        }, $plays);

        broadcast(new \App\Events\RoundEnded($game->room_id, $game->id, $game->current_round, $winnerId, $broadcastPlays));

        $roundNumber = $game->current_round;

        // After round 4: evaluate tồn
        if ($roundNumber === 4) {
            $this->handlePostRound4($game, $activePlayers, $hands);
        } elseif ($roundNumber === 5) {
            $round6 = Round::create([
                'game_id' => $game->id,
                'round_number' => 6,
                'lead_player_id' => $winnerId,
                'participant_count' => count($activePlayers),
                'started_at' => now(),
            ]);

            $game->update([
                'current_round' => 6,
                'current_player_id' => null,
                'turn_started_at' => null,
            ]);

            $this->resolveFinalRoundAutomatically($game, $round6, $winnerId, $activePlayers, $hands);
        } elseif ($roundNumber >= 6) {
            // Game ends - round 6 winner wins the game
            // Fetch eliminated players (gục tùng after round 4) for penalty scoring
            $eliminatedIds = Player::where('room_id', $game->room_id)
                ->where('status', 'eliminated')
                ->pluck('id')
                ->toArray();
            $this->endGame($game, $winnerId, 'normal', $eliminatedIds, $hands, $plays, $roundNumber);
        } else {
            // Continue to next round
            $nextRound = $roundNumber + 1;
            $game->update([
                'current_round' => $nextRound,
                'current_player_id' => $winnerId,
                'turn_started_at' => now(),
            ]);

            Round::create([
                'game_id' => $game->id,
                'round_number' => $nextRound,
                'lead_player_id' => $winnerId,
                'participant_count' => count($activePlayers),
                'started_at' => now(),
            ]);

            broadcast(new \App\Events\TurnStarted($game->room_id, $game->id, $winnerId, now()));
        }
    }

    private function handlePostRound4(Game $game, array $activePlayers, array $hands): void
    {
        $roundWinners = $game->rounds()
            ->where('round_number', '<=', 4)
            ->pluck('winner_id')
            ->toArray();

        $result = $this->engine->evaluatePostRound4($roundWinners, $activePlayers);

        // Persist players who cannot enter round 5; they keep watching as spectators.
        foreach ($result['eliminated'] as $eliminatedId) {
            Player::where('id', $eliminatedId)->update(['status' => 'eliminated']);
            broadcast(new \App\Events\YourHand($eliminatedId, []));
            broadcast(new \App\Events\PlayerEliminated($game->room_id, $game->id, $eliminatedId, 'guc_tung'));
        }

        if (!$result['continue']) {
            // Thắng tùng
            $this->endGame($game, $result['winner'], 'thang_tung', $result['eliminated'], $hands);
        } else {
            // Continue to round 5 (chưng phase) — only survivors
            $lastRoundWinner = $game->rounds()->where('round_number', 4)->value('winner_id');

            $game->update([
                'phase' => 'chung',
                'current_round' => 5,
                'current_player_id' => $lastRoundWinner,
                'turn_started_at' => now(),
            ]);

            Round::create([
                'game_id' => $game->id,
                'round_number' => 5,
                'lead_player_id' => $lastRoundWinner,
                'participant_count' => count($activePlayers) - count($result['eliminated']),
                'started_at' => now(),
            ]);

            broadcast(new \App\Events\TurnStarted($game->room_id, $game->id, $lastRoundWinner, now()));
        }
    }

    private function resolveFinalRoundAutomatically(Game $game, Round $round, int $leadPlayerId, array $activePlayers, array $hands): void
    {
        $participants = array_values(array_filter(
            $activePlayers,
            fn (int $playerId) => !empty($hands[$playerId] ?? [])
        ));

        if (count($participants) <= 1) {
            $this->finishByRemainingPlayer($game, $hands);
            return;
        }

        $seatPositions = $this->getSeatPositions($game->room_id, $participants);
        $playOrder = $this->buildPlayOrder($leadPlayerId, $participants, $seatPositions);

        if (empty($playOrder)) {
            $this->finishByRemainingPlayer($game, $hands);
            return;
        }

        foreach ($playOrder as $index => $playerId) {
            $card = $hands[$playerId][0] ?? null;
            if (!$card) {
                continue;
            }

            Play::create([
                'round_id' => $round->id,
                'player_id' => $playerId,
                'card' => $card,
                'is_face_down' => false,
                'play_order' => $index + 1,
                'created_at' => now(),
            ]);

            if ($index === 0) {
                $round->update(['lead_suit' => $this->engine->parseCard($card)['suit']]);
            }

            $hands = $this->engine->removeCardFromHand($hands, $playerId, $card);

            broadcast(new \App\Events\CardPlayed(
                $game->room_id,
                $game->id,
                $playerId,
                $card,
                false,
                6,
                $index + 1,
            ));
            broadcast(new \App\Events\YourHand($playerId, $hands[$playerId] ?? []));
        }

        $game->update(['hands' => $hands]);
        $this->completeRound($game, $round, $participants, $hands);
    }

    private function endGame(
        Game $game,
        ?int $winnerId,
        string $winType,
        array $eliminated,
        array $hands,
        array $tablePlays = [],
        ?int $finalRoundNumber = null
    ): void
    {
        $room = Room::find($game->room_id);

        $scores = $this->engine->calculateScores($winnerId, $winType, $eliminated, $hands, $room->thoi_ach_enabled);

        $game->update([
            'phase' => 'finished',
            'winner_id' => $winnerId,
        ]);

        $room->update([
            'status' => 'waiting',
            'next_game_deadline_at' => now()->addSeconds(Room::NEXT_GAME_READY_TIMEOUT_SECONDS),
        ]);

        // Restore eliminated and kicked players back to connected (they can play next game)
        Player::where('room_id', $room->id)
            ->whereIn('status', ['eliminated', 'kicked'])
            ->update(['status' => 'connected']);

        Player::where('room_id', $room->id)
            ->whereIn('status', ['connected', 'disconnected'])
            ->update(['ready_for_next_game' => false]);

        $activePlayers = Player::where('room_id', $room->id)
            ->whereIn('status', ['connected', 'disconnected'])
            ->get();

        $this->updateScores($room, $scores, $activePlayers, $winnerId);

        broadcast(new \App\Events\GameEnded(
            $room->id,
            $game->id,
            $winnerId,
            $winType,
            null,
            $hands, // reveal all hands at end
            $scores,
            $tablePlays,
            $finalRoundNumber
        ));
    }

    private function updateScores(Room $room, array $scores, $activePlayers, ?int $winnerId): void
    {
        foreach ($activePlayers as $player) {
            $delta = $scores[$player->id] ?? 0;

            $score = Score::firstOrCreate(
                ['room_id' => $room->id, 'guest_token' => $player->guest_token],
                ['player_name' => $player->name]
            );

            $score->total_points += $delta;
            if ($winnerId && (int) $player->id === (int) $winnerId) {
                $score->games_won++;
            } else {
                $score->games_lost++;
            }
            if (in_array($player->id, array_keys(array_filter($scores, fn ($s) => $s < 0)))) {
                $score->tung_deaths++;
            }
            $score->updated_at = now();
            $score->save();
        }
    }

    private function getFirstLeader(Room $room, $activePlayers): int
    {
        // First game: owner leads. Subsequent games: previous game winner.
        $lastGame = Game::where('room_id', $room->id)
            ->where('phase', 'finished')
            ->latest('started_at')
            ->first();

        if ($lastGame && $lastGame->winner_id) {
            $winner = $activePlayers->firstWhere('id', $lastGame->winner_id);
            if ($winner) return $winner->id;
        }

        // Owner or first player
        $owner = $activePlayers->firstWhere('id', $room->owner_player_id);
        return $owner ? $owner->id : $activePlayers->first()->id;
    }

    private function getActivePlayersForGame(Game $game): array
    {
        return Player::where('room_id', $game->room_id)
            ->whereIn('status', ['connected', 'disconnected'])
            ->pluck('id')
            ->toArray();
    }

    private function getSeatPositions(int $roomId, array $playerIds): array
    {
        return Player::where('room_id', $roomId)
            ->whereIn('id', $playerIds)
            ->pluck('seat_position', 'id')
            ->toArray();
    }

    private function getNextPlayerAfterSeat(int $seatPosition, array $activePlayers, array $seatPositions): ?int
    {
        if (empty($activePlayers)) {
            return null;
        }

        $sorted = [];
        foreach ($activePlayers as $playerId) {
            $sorted[$playerId] = $seatPositions[$playerId] ?? 0;
        }
        asort($sorted);

        foreach ($sorted as $playerId => $seat) {
            if ($seat > $seatPosition) {
                return (int) $playerId;
            }
        }

        return (int) array_key_first($sorted);
    }

    private function buildPlayOrder(int $leadPlayerId, array $activePlayers, array $seatPositions): array
    {
        if (empty($activePlayers)) {
            return [];
        }

        $sorted = [];
        foreach ($activePlayers as $playerId) {
            $sorted[$playerId] = $seatPositions[$playerId] ?? 0;
        }
        asort($sorted);

        $playerOrder = array_map('intval', array_keys($sorted));
        $leadIndex = array_search($leadPlayerId, $playerOrder, true);

        if ($leadIndex === false) {
            return $playerOrder;
        }

        return array_merge(
            array_slice($playerOrder, $leadIndex),
            array_slice($playerOrder, 0, $leadIndex)
        );
    }
}
