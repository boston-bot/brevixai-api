<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgentTool
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = (string) config('services.brevix_agent.api_key', '');
        if ($expectedKey === '') {
            return response()->json(['error' => 'Agent tool authentication is not configured'], 503);
        }

        $providedKey = $request->bearerToken() ?: $request->header('X-Brevix-Agent-Key');
        if (!$providedKey || !hash_equals($expectedKey, $providedKey)) {
            return response()->json(['error' => 'Unauthorized agent tool request'], 401);
        }

        return $next($request);
    }
}
