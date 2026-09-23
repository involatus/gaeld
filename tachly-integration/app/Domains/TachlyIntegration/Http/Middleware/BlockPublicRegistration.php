<?php

namespace App\Domains\TachlyIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This Gäld instance exists solely to host Organizations Tachly provisions
 * via the internal API (`ClubProvisioningService`) — there is no legitimate
 * path for a member of the public to self-register a new account or
 * Organization here. Gäld's own self-registration (`/register`) is wide
 * open by default in Community Edition mode: `RegisteredUserController`
 * only redirects away from it when `FEATURE_SAAS` is on (this instance runs
 * with it off, per its own docker-compose env), so with no gate at all,
 * anyone who found `gaeld.tachly.app/register` could create a real account
 * on a production instance meant to be Tachly-managed only.
 *
 * Blocks both the form (`GET /register`) and the submit
 * (`POST /register`) by route name, redirecting to `/login` — a real
 * visitor landing here via a stale bookmark or a search index deserves the
 * normal sign-in page, not an opaque 404.
 */
class BlockPublicRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register') || $request->routeIs('register.store')) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
