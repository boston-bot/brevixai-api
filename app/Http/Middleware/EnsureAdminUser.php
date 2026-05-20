<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminUser
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $adminEmails = config('admin.emails', []);

        if (
            ! $user
            || ($user->role !== 'admin' && ! in_array(strtolower($user->email), $adminEmails, true))
        ) {
            abort(403, 'Admin access required');
        }

        return $next($request);
    }
}
