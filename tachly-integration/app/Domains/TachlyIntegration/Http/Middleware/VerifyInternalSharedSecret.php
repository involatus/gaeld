<?php

namespace App\Domains\TachlyIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the internal `/internal/tachly/*` route group. Only Tachly's own
 * server and this Gäld container know the shared secret (set as the
 * `TACHLY_INTERNAL_SHARED_SECRET` env var on both sides) — this is not a
 * per-club credential and never appears in any club-facing response.
 */
class VerifyInternalSharedSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('tachly-integration.shared_secret');
        $provided = $request->header('X-Tachly-Internal-Secret', '');

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, (string) $provided)) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'unauthenticated',
            ], 401);
        }

        return $next($request);
    }
}
