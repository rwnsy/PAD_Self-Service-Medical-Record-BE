<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNonProductionEnvironment
{
    /**
     * Memastikan halaman pengujian developer hanya dapat diakses pada environment non-production (local / testing).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() || config('app.env') === 'production') {
            abort(404);
        }

        return $next($request);
    }
}
