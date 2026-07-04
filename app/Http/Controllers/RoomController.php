<?php

namespace App\Http\Controllers;

use App\Events\PlayerJoined;
use App\Events\PlayerLeft;
use App\Events\RoomRemoved;
use App\Events\RoomUpdated;
use App\Models\Player;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RoomController extends Controller
{
    public function index()
    {
        $this->cleanupEmptyWaitingRooms();

        $rooms = Room::where('status', 'waiting')
            ->where('is_private', false)
            ->whereHas('activePlayers')
            ->withCount(['activePlayers as player_count'])
            ->latest()
            ->get();

        return Inertia::render('Lobby', [
            'rooms' => $rooms,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|min:2|max:30',
            'max_players' => 'required|integer|min:2|max:6',
            'is_private' => 'boolean',
            'thoi_ach_enabled' => 'boolean',
        ]);

        $room = Room::create([
            'code' => Room::generateCode(),
            'name' => $request->name,
            'max_players' => $request->max_players,
            'is_private' => $request->boolean('is_private'),
            'thoi_ach_enabled' => $request->boolean('thoi_ach_enabled'),
        ]);

        // Auto-join creator
        $player = $this->createPlayer($room);
        $room->update(['owner_player_id' => $player->id]);

        broadcast(new PlayerJoined($player, $room->id))->toOthers();

        // Notify lobby about new room
        broadcast(new \App\Events\RoomCreated([
            'id' => $room->id,
            'code' => $room->code,
            'name' => $room->name,
            'max_players' => $room->max_players,
            'is_private' => $room->is_private,
            'thoi_ach_enabled' => $room->thoi_ach_enabled,
            'status' => $room->status,
            'player_count' => 1,
        ]));

        return redirect("/rooms/{$room->code}");
    }

    public function join(Request $request, Room $room)
    {
        if (!$room->isJoinable()) {
            return back()->withErrors(['room' => 'Phòng đã đầy hoặc đang chơi.']);
        }

        // Check if already in this room
        $existing = $room->players()
            ->where('guest_token', session('guest_token'))
            ->whereIn('status', ['connected', 'disconnected'])
            ->first();

        if ($existing) {
            $existing->update([
                'session_id' => session()->getId(),
                'status' => 'connected',
                'last_active_at' => now(),
            ]);
            broadcast(new PlayerJoined($existing, $room->id))->toOthers();
        } else {
            $player = $this->createPlayer($room);
            broadcast(new PlayerJoined($player, $room->id))->toOthers();
        }

        return redirect("/rooms/{$room->code}");
    }

    public function show(Room $room)
    {
        $this->processPendingNextGame($room);
        $room->refresh();
        return Inertia::render('Room', $this->buildRoomState($room));
    }

    public function state(Room $room)
    {
        $this->processPendingNextGame($room);
        $room->refresh();
        $state = $this->buildRoomState($room);

        if (!$state['currentPlayer']) {
            return response()->json(['error' => 'Not a member of this room'], 403);
        }

        return response()->json($state);
    }

    public function readyNextGame(Room $room)
    {
        $player = $room->players()
            ->where('guest_token', session('guest_token'))
            ->whereIn('status', ['connected', 'disconnected'])
            ->first();

        if (!$player) {
            return response()->json(['error' => 'Not a member of this room'], 403);
        }

        if ($room->status !== 'waiting' || !$room->next_game_deadline_at) {
            return response()->json(['error' => 'Không có ván mới đang chờ xác nhận.'], 422);
        }

        $player->update([
            'ready_for_next_game' => true,
            'status' => 'connected',
            'last_active_at' => now(),
        ]);

        $room->touch();
        broadcast(new RoomUpdated($room->fresh()));

        $this->processPendingNextGame($room->fresh());

        return response()->json(['success' => true]);
    }

    public function leave(Room $room)
    {
        $player = $room->players()
            ->where('guest_token', session('guest_token'))
            ->whereIn('status', ['connected', 'disconnected', 'eliminated', 'kicked'])
            ->first();

        if (!$player) {
            return redirect('/lobby');
        }

        $wasActiveParticipant = in_array($player->status, ['connected', 'disconnected']);

        $player->update([
            'status' => 'left',
            'seat_position' => $room->getReleasedSeatPosition($player->id),
        ]);

        if ($room->status === 'playing') {
            $activeGame = \App\Models\Game::where('room_id', $room->id)
                ->where('phase', '!=', 'finished')
                ->latest('started_at')
                ->first();

            if ($activeGame) {
                app(GameController::class)->handlePlayerLeftDuringGame($activeGame, $player, $wasActiveParticipant);
                $room->refresh();
            }
        }

        if ($this->roomHasNoOccupants($room)) {
            broadcast(new RoomRemoved($room->id));
            $room->delete();
            return redirect('/lobby');
        }

        // If owner leaves, assign next connected player as owner
        if ($room->owner_player_id === $player->id) {
            $nextOwner = $room->players()
                ->whereIn('status', ['connected', 'disconnected'])
                ->where('id', '!=', $player->id)
                ->orderBy('seat_position')
                ->first();

            $room->update(['owner_player_id' => $nextOwner?->id]);
        }

        broadcast(new PlayerLeft($player->id, $room->id, $player->name, $room->owner_player_id))->toOthers();

        return redirect('/lobby');
    }

    private function createPlayer(Room $room): Player
    {
        $seatPosition = $room->getNextSeatPosition();
        if ($seatPosition === null) {
            throw ValidationException::withMessages([
                'room' => 'Phòng đã đầy.',
            ]);
        }

        return Player::create([
            'room_id' => $room->id,
            'session_id' => session()->getId(),
            'guest_token' => session('guest_token'),
            'name' => session('guest_name'),
            'seat_position' => $seatPosition,
            'status' => 'connected',
            'last_active_at' => now(),
        ]);
    }

    private function seatOccupyingStatuses(): array
    {
        return Room::SEAT_OCCUPYING_STATUSES;
    }

    private function roomHasNoOccupants(Room $room): bool
    {
        return !$room->players()
            ->whereIn('status', $this->seatOccupyingStatuses())
            ->exists();
    }

    private function cleanupEmptyWaitingRooms(): void
    {
        $cutoff = now()->subSeconds(Room::WAITING_ROOM_STALE_SECONDS);

        Room::where('status', 'waiting')
            ->whereHas('players', function ($q) use ($cutoff) {
                $q->whereIn('status', ['connected', 'disconnected'])
                    ->where(function ($q) use ($cutoff) {
                        $q->whereNull('last_active_at')
                            ->orWhere('last_active_at', '<=', $cutoff);
                    });
            })
            ->with(['players' => function ($q) use ($cutoff) {
                $q->whereIn('status', ['connected', 'disconnected'])
                    ->where(function ($q) use ($cutoff) {
                        $q->whereNull('last_active_at')
                            ->orWhere('last_active_at', '<=', $cutoff);
                    });
            }])
            ->get()
            ->each(function (Room $room) {
                foreach ($room->players as $player) {
                    $player->update([
                        'status' => 'left',
                        'seat_position' => $room->getReleasedSeatPosition($player->id),
                    ]);
                }
            });

        Room::where('status', 'waiting')
            ->whereDoesntHave('players', function ($q) {
                $q->whereIn('status', $this->seatOccupyingStatuses());
            })
            ->each(function (Room $room) {
                $roomId = $room->id;
                $room->delete();
                broadcast(new RoomRemoved($roomId));
            });
    }

    private function processPendingNextGame(Room $room): void
    {
        if ($room->status !== 'waiting' || !$room->next_game_deadline_at) {
            return;
        }

        $room->load(['players' => function ($q) {
            $q->whereIn('status', ['connected', 'disconnected'])->orderBy('seat_position');
        }]);

        $participants = $room->players;
        if ($participants->isEmpty()) {
            $room->update(['next_game_deadline_at' => null]);
            return;
        }

        $readyPlayers = $participants->where('ready_for_next_game', true);

        if ($participants->count() >= 2 && $readyPlayers->count() === $participants->count()) {
            app(GameController::class)->startReadyPlayersGame($room->fresh());
            return;
        }

        if (now()->lt($room->next_game_deadline_at)) {
            return;
        }

        $removedPlayers = $participants->where('ready_for_next_game', false)->values();

        foreach ($removedPlayers as $player) {
            $player->update([
                'status' => 'left',
                'seat_position' => $room->getReleasedSeatPosition($player->id),
                'ready_for_next_game' => false,
            ]);
        }

        $room->refresh();

        if ($room->owner_player_id && $removedPlayers->contains('id', $room->owner_player_id)) {
            $nextOwner = $room->players()
                ->whereIn('status', ['connected', 'disconnected'])
                ->orderBy('seat_position')
                ->first();
            $room->update(['owner_player_id' => $nextOwner?->id]);
        }

        foreach ($removedPlayers as $player) {
            broadcast(new PlayerLeft($player->id, $room->id, $player->name, $room->fresh()->owner_player_id))->toOthers();
        }

        if ($this->roomHasNoOccupants($room->fresh())) {
            broadcast(new RoomRemoved($room->id));
            $room->delete();
            return;
        }

        $remainingPlayers = $room->fresh()->players()
            ->whereIn('status', ['connected', 'disconnected'])
            ->orderBy('seat_position')
            ->get();

        if ($remainingPlayers->count() >= 2 && $remainingPlayers->where('ready_for_next_game', true)->count() >= 2) {
            app(GameController::class)->startReadyPlayersGame($room->fresh());
            return;
        }

        $remainingPlayers->each(fn (Player $player) => $player->update(['ready_for_next_game' => false]));
        $room->update(['next_game_deadline_at' => null]);
        broadcast(new RoomUpdated($room->fresh()));
    }

    private function buildRoomState(Room $room): array
    {
        $visibleStatuses = $room->status === 'playing'
            ? ['connected', 'disconnected', 'eliminated', 'kicked']
            : ['connected', 'disconnected'];

        $room->load(['players' => function ($q) use ($visibleStatuses) {
            $q->whereIn('status', $visibleStatuses)->orderBy('seat_position');
        }]);

        $currentPlayer = Player::where('room_id', $room->id)
            ->where('guest_token', session('guest_token'))
            ->whereIn('status', $visibleStatuses)
            ->first();

        if ($currentPlayer && in_array($currentPlayer->status, ['connected', 'disconnected'])) {
            $currentPlayer->update([
                'status' => 'connected',
                'last_active_at' => now(),
            ]);
            $room->touch();
        }

        $game = null;
        if ($room->status === 'playing') {
            $activeGame = \App\Models\Game::where('room_id', $room->id)
                ->where('phase', '!=', 'finished')
                ->latest('started_at')
                ->first();

            if ($activeGame) {
                // Sanitize game - never expose hands or deck state to client.
                $game = $activeGame->only([
                    'id', 'room_id', 'game_number', 'phase',
                    'current_round', 'current_player_id',
                    'instant_winner_id', 'instant_win_type',
                    'winner_id', 'turn_started_at', 'started_at',
                ]);
            }
        }

        $lastGame = \App\Models\Game::where('room_id', $room->id)
            ->where('phase', 'finished')
            ->whereNotNull('winner_id')
            ->latest('started_at')
            ->first();

        $lastWinner = $lastGame
            ? Player::where('id', $lastGame->winner_id)->first()?->only(['id', 'name'])
            : null;

        return [
            'room' => $room->only(['id', 'code', 'name', 'max_players', 'is_private', 'thoi_ach_enabled', 'status', 'owner_player_id', 'next_game_deadline_at']),
            'players' => $room->players,
            'currentPlayer' => $currentPlayer,
            'isOwner' => $room->owner_player_id === $currentPlayer?->id,
            'game' => $game,
            'lastWinner' => $lastWinner,
        ];
    }
}
