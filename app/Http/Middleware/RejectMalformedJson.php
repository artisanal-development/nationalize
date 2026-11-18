<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel treats an unparseable JSON body as an empty one, which would surface as a
 * misleading 422 about a missing field. Answer 400 instead.
 */
class RejectMalformedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $content = $request->getContent();

        if ($content !== '' && $request->isJson()) {
            json_decode($content);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['message' => 'Malformed JSON.'], 400);
            }
        }

        return $next($request);
    }
}
