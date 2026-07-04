<?php

namespace App\Http\Middleware;

use App\Broadcasting\GuestBroadcastUser;
use App\Models\Player;
use Closure;
use Illuminate\Http\Request;

/**
 * Sets a guest "user" on the request so Laravel Broadcasting
 * can authorize presence/private channels without a real auth system.
 */
class SetGuestBroadcastUser
{
    public function handle(Request $request, Closure $next)
    {
        $guestToken = session('guest_token');
        $guestName = session('guest_name');

        if ($guestToken && $guestName) {
            // Find any active player for this guest to get an ID
            $player = Player::where('guest_token', $guestToken)
                ->whereIn('status', ['connected', 'disconnected', 'eliminated', 'kicked'])
                ->first();

            $userId = $player?->id ?? crc32($guestToken); // fallback ID

            $request->setUserResolver(fn () => new GuestBroadcastUser(
                id: $userId,
                name: $guestName,
                guestToken: $guestToken,
            ));
        }

        return $next($request);
    }
}
