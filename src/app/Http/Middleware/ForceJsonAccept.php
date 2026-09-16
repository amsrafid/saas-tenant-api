<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Treats every API request as asking for JSON, so no framework branch on `expectsJson()` can answer with HTML or a redirect.
 */
class ForceJsonAccept
{
    /**
     * Overwrite the Accept header before anything downstream reads it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
