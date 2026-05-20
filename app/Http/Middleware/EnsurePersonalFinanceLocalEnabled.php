<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePersonalFinanceLocalEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $environments = config('personal_finance.route_environments', ['local']);

        if (! config('personal_finance.enabled') || ! app()->environment($environments)) {
            abort(404);
        }

        return $next($request);
    }
}
