<?php

use App\Events\ChatMessage;
use App\Events\Reaction;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\RoomController;
use App\Models\Room;
use App\Models\Score;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (session('guest_token') && session('guest_name')) {
        return redirect('/lobby');
    }
    return Inertia::render('Home');
});

Route::post('/guest', [GuestController::class, 'store']);
Route::post('/guest/restore', [GuestController::class, 'restore']);
Route::post('/logout', function () {
    session()->forget(['guest_token', 'guest_name']);
    return redirect('/')->withCookie(cookie()->forget('catte_guest_token'));
});

Route::middleware('guest.required')->group(function () {
    Route::get('/lobby', [RoomController::class, 'index']);
    Route::post('/rooms', [RoomController::class, 'store']);
    Route::post('/rooms/{room:code}/join', [RoomController::class, 'join']);
    Route::post('/rooms/{room:code}/leave', [RoomController::class, 'leave']);
    Route::post('/rooms/{room:code}/ready-next', [RoomController::class, 'readyNextGame']);
    Route::get('/rooms/{room:code}', [RoomController::class, 'show']);
    Route::get('/api/rooms/{room}/state', [RoomController::class, 'state']);

    // Game routes
    Route::post('/rooms/{room:code}/start', [GameController::class, 'start']);
    Route::post('/api/game/{game}/play', [GameController::class, 'playCard']);
    Route::post('/api/game/{game}/claim-timeout', [GameController::class, 'claimTimeout']);
    Route::get('/api/game/{game}/my-hand', [GameController::class, 'getMyHand']);

    // Chat & Reactions
    Route::post('/api/rooms/{room}/chat', function (Request $request, Room $room) {
        $request->validate(['message' => 'required|string|max:100']);

        // Verify sender is a member of this room
        $player = $room->players()
            ->where('guest_token', session('guest_token'))
            ->whereIn('status', ['connected', 'disconnected'])
            ->first();
        if (!$player) {
            return response()->json(['error' => 'Not a member of this room'], 403);
        }

        broadcast(new ChatMessage($room->id, $player->name, $request->message))->toOthers();
        return response()->json(['ok' => true]);
    })->middleware('throttle:5,10');

    Route::post('/api/rooms/{room}/reaction', function (Request $request, Room $room) {
        $request->validate(['emoji' => 'required|string|max:4']);

        // Verify sender is a member of this room
        $player = $room->players()
            ->where('guest_token', session('guest_token'))
            ->whereIn('status', ['connected', 'disconnected'])
            ->first();
        if (!$player) {
            return response()->json(['error' => 'Not a member of this room'], 403);
        }

        broadcast(new Reaction($room->id, $player->name, $request->emoji))->toOthers();
        return response()->json(['ok' => true]);
    })->middleware('throttle:10,10');

    // Scoreboard
    Route::get('/api/rooms/{room}/scores', function (Room $room) {
        $scores = Score::where('room_id', $room->id)
            ->orderByDesc('total_points')
            ->orderByDesc('games_won')
            ->get();
        return response()->json($scores);
    });

    Route::get('/api/rooms/{room}/scores/csv', function (Room $room) {
        $scores = Score::where('room_id', $room->id)
            ->orderByDesc('total_points')
            ->get();

        $csv = "Tên,Thắng,Thua,Gục Tùng,Thối Ách,Thắng Trắng,Tổng Điểm\n";
        foreach ($scores as $s) {
            $csv .= "{$s->player_name},{$s->games_won},{$s->games_lost},{$s->tung_deaths},{$s->thoi_ach_count},{$s->instant_wins},{$s->total_points}\n";
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="scores-' . $room->code . '.csv"');
    });
});
