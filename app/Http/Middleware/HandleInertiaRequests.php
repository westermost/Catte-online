<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'guest' => [
                'name' => session('guest_name'),
            ],
            'flash' => [
                'error' => fn () => $request->session()->get('errors')?->first(),
            ],
        ]);
    }
}
