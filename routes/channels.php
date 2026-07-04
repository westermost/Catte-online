<?php

use App\Broadcasting\GuestBroadcastUser;
use App\Models\Player;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('room.{roomId}', function (GuestBroadcastUser $user, $roomId) {
    $player = Player::where('guest_token', $user->guestToken)
        ->where('room_id', $roomId)
        ->whereIn('status', ['connected', 'disconnected', 'eliminated', 'kicked'])
        ->first();

    if (!$player) {
        return false;
    }

    return [
        'id' => $player->id,
        'name' => $player->name,
        'seat_position' => $player->seat_position,
    ];
});

Broadcast::channel('player.{playerId}', function (GuestBroadcastUser $user, $playerId) {
    $player = Player::where('id', $playerId)
        ->where('guest_token', $user->guestToken)
        ->first();

    return $player !== null;
});
