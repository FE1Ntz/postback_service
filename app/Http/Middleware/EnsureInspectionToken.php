<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInspectionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('partner.inspection_token');

        // Do not use filled(): even a whitespace token must require authentication.
        if (is_string($token) && $token !== '' && ! hash_equals($token, $request->bearerToken() ?? '')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
