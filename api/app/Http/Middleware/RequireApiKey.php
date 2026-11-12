<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional shared-secret guard for the local API.
 * Disabled when `sovera.api_key` is empty.
 */
class RequireApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('sovera.api_key', '');

        if ($expected === '') {
            return $next($request);
        }

        $given = (string) ($request->header('X-Api-Key') ?? $request->bearerToken() ?? ($request->isMethodSafe() ? $request->query('api_key') : null) ?? '');

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid or missing API key.'], 401);
        }

        return $next($request);
    }
}
