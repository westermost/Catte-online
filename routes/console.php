<?php

use Illuminate\Support\Facades\Schedule;
use App\Events\RoomRemoved;
use App\Models\Game;
use App\Models\Room;
use App\Services\CatteGameEngine;

// Backup cron: check stale turns > 60s
Schedule::call(function () {
    $engine = app(CatteGameEngine::class);

    $staleGames = Game::where('phase', '!=', 'finished')
        ->whereNotNull('turn_started_at')
        ->where('turn_started_at', '<=', now()->subSeconds(60))
        ->get();

    foreach ($staleGames as $game) {
        // Force timeout via same logic as claimTimeout
        // This is a safety net - normally clients handle this
        try {
            app(\App\Http\Controllers\GameController::class)->forceTimeout($game);
        } catch (\Throwable $e) {
            \Log::warning("Cron timeout failed for game {$game->id}: " . $e->getMessage());
        }
    }
})->everyMinute()->name('cleanup-stale-turns');

// Cleanup old rooms (inactive > 30 minutes)
Schedule::call(function () {
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
            $q->whereIn('status', ['connected', 'disconnected', 'eliminated', 'kicked']);
        })
        ->each(function (Room $room) {
            $roomId = $room->id;
            $room->delete();
            broadcast(new RoomRemoved($roomId));
        });
})->everyFiveMinutes()->name('cleanup-inactive-rooms');
