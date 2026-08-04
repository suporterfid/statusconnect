<?php

// Mirrors TaskConnect: app/Http/Middleware/EnforceSubmitRateLimit.php

namespace App\Http\Middleware;

use App\Application\RateLimiting\DatabaseRateLimiter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforcePublicStatusPageRateLimit
{
    public function __construct(private readonly DatabaseRateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->limiter->hitOrFail(
            'status-page:' . hash('sha256', (string) $request->ip()),
            (int) config('status_page.public_rate_limit_per_minute', 60),
        );

        return $next($request);
    }
}
