<?php

namespace App\Http\Controllers;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class GuestController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|min:2|max:20',
        ]);

        $guestToken = Str::uuid()->toString();

        session([
            'guest_token' => $guestToken,
            'guest_name' => $request->name,
        ]);

        // Set httpOnly cookie with 30 days TTL for reconnect
        $cookie = cookie('catte_guest_token', $guestToken, 60 * 24 * 30, '/', null, false, true);

        return redirect('/lobby')->withCookie($cookie);
    }

    public function restore(Request $request)
    {
        $guestToken = $request->cookie('catte_guest_token');

        if (!$guestToken) {
            return redirect('/');
        }

        // Find most recent player record with this guest_token
        $player = Player::where('guest_token', $guestToken)
            ->whereIn('status', ['connected', 'disconnected'])
            ->latest()
            ->first();

        if (!$player) {
            return redirect('/');
        }

        // Update session_id to new session
        $player->update(['session_id' => session()->getId()]);

        // Restore session data
        session([
            'guest_token' => $guestToken,
            'guest_name' => $player->name,
        ]);

        return redirect("/rooms/{$player->room->code}");
    }
}
