<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FeedTokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.feed.token');
        $providedToken = $request->bearerToken() ?? $request->input('token');

        if (!is_string($expectedToken) || $expectedToken === '') {
            return response()->json([
                'message' => 'Feed token is not configured.',
            ], 500);
        }

        if (!is_string($providedToken) || $providedToken === '') {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        if (!hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}
