<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API is a *local* service for the Sovera Mail client. Three checks keep
 * random web pages (running in the user's browser) away from the mailbox:
 *
 *  1. Host allow-list   - defeats DNS-rebinding (attacker.com -> 127.0.0.1).
 *  2. Origin allow-list - browsers always send Origin on cross-site requests.
 *  3. Custom header on state-changing API calls - forces a CORS preflight,
 *     which foreign origins fail, so a hidden <form> POST cannot send mail.
 */
class LocalClientOnly
{
    public const CLIENT_HEADER = 'X-Sovera-Client';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hostAllowed($request)) {
            return response()->json(['message' => 'Unrecognised Host header.'], 421);
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && ! $this->originAllowed($origin)) {
            return response()->json(['message' => 'Origin not allowed.'], 403);
        }

        if ($request->is('api/*')
            && ! $request->isMethodSafe()
            && ! $request->headers->has(self::CLIENT_HEADER)) {
            return response()->json(['message' => 'Missing '.self::CLIENT_HEADER.' header.'], 403);
        }

        return $next($request);
    }

    private function hostAllowed(Request $request): bool
    {
        $host = strtolower($request->getHost());   // without port

        return in_array($host, $this->allowedHosts(), true);
    }

    private function originAllowed(string $origin): bool
    {
        $origin = strtolower(rtrim($origin, '/'));
        if ($origin === 'null') {
            return false;
        }

        return in_array($origin, array_map(fn ($o) => strtolower(rtrim($o, '/')), (array) config('cors.allowed_origins')), true);
    }

    /** @return string[] */
    private function allowedHosts(): array
    {
        $hosts = ['localhost', '127.0.0.1', '::1', '[::1]', 'host.docker.internal', 'api', 'nginx'];

        foreach ([config('app.url'), config('sovera.frontend_url')] as $url) {
            $h = parse_url((string) $url, PHP_URL_HOST);
            if ($h) {
                $hosts[] = strtolower($h);
            }
        }

        foreach ((array) config('sovera.extra_hosts', []) as $h) {
            $hosts[] = strtolower((string) $h);
        }

        return array_values(array_unique($hosts));
    }
}
