<?php

namespace App\Http\Middleware;

use App\Infrastructure\Persistence\Eloquent\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = $user instanceof User
            ? ($user->preferences?->locale ?? config('app.locale'))
            : config('app.locale');
        App::setLocale($locale);

        return $next($request);
    }
}
