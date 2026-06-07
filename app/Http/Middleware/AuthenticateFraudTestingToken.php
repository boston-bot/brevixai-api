<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFraudTestingToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = (string) config('services.fraud_testing.api_key', '');
        if ($expectedKey === '') {
            return response()->json(['error' => 'Fraud testing token is not configured'], 503);
        }

        $providedKey = $request->bearerToken() ?: $request->header('X-Brevix-Agent-Token');
        if (! $providedKey || ! hash_equals($expectedKey, $providedKey)) {
            Log::warning('fraud_testing.auth_failed', [
                'endpoint' => $request->method() . ' ' . $request->path(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
