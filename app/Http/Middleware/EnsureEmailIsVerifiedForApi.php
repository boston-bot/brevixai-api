<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON variant of the framework's `verified` middleware: returns a structured
 * 403 with a stable code the frontend can route on, instead of an HTML abort.
 */
class EnsureEmailIsVerifiedForApi
{
    private static ?bool $columnExists = null;

    public function handle(Request $request, Closure $next): Response
    {
        // Environments migrated before email verification existed (and older
        // test schemas) have no email_verified_at column; skip enforcement
        // there. Mirrors the Schema-guard pattern used by PlanPolicyService.
        self::$columnExists ??= Schema::hasColumn('users', 'email_verified_at');
        if (! self::$columnExists) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'error' => 'Your email address is not verified.',
                'code' => 'EMAIL_UNVERIFIED',
            ], 403);
        }

        return $next($request);
    }

    public static function flushColumnCache(): void
    {
        self::$columnExists = null;
    }
}
