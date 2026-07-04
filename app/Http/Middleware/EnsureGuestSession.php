<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!session('guest_token') || !session('guest_name')) {
            return redirect('/');
        }

        return $next($request);
    }
}
