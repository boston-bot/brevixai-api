<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            Log::warning('agent_tool.auth_failed', [
                'tool_endpoint' => $request->method() . ' ' . $request->path(),
                'company_id' => $request->route('companyId'),
                'agent_request_id' => $request->header('X-Brevix-Agent-Request-Id'),
            ]);

            return response()->json(['error' => 'Unauthorized agent tool request'], 401);
        }

        $response = $next($request);

        Log::info('agent_tool.called', [
            'tool_endpoint' => $request->method() . ' ' . $request->path(),
            'company_id' => $request->route('companyId'),
            'user_id' => $request->header('X-Brevix-User-Id'),
            'agent_request_id' => $request->header('X-Brevix-Agent-Request-Id'),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
