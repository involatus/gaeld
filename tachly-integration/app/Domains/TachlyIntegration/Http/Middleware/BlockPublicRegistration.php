<?php

namespace App\Domains\TachlyIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This Gäld instance exists solely to host Organizations Tachly provisions
 * via the internal API (`ClubProvisioningService`) — there is no legitimate
 * path for a member of the public to create an account or Organization here
 * by hand. Blocks two separate upstream entry points, both wide open by
 * default in Community Edition mode (this instance runs `FEATURE_SAAS=false`,
 * and neither route gates on anything else):
 *
 *  - `/register` (`RegisteredUserController`): ordinary self-registration,
 *    open unconditionally unless SaaS mode redirects it to `/signup`.
 *  - `/setup` (`SetupWizardController`): the *worse* one — creates the
 *    FIRST user AND Organization for the whole instance and logs them in
 *    immediately, gated only by `Organization::exists()`. Every Organization
 *    on this instance is normally Tachly-provisioned, so that gate is
 *    permanently closed — until an org gets deleted (e.g. `deprovision()`
 *    clearing test data) and the count briefly hits zero, at which point
 *    `/setup` becomes a live, unauthenticated path to founding-admin access
 *    on a production instance. Confirmed exploitable in exactly that window.
 *
 * Blocks both GET and POST for each, by route name, redirecting to `/login`
 * — a real visitor landing here via a stale bookmark or a search index
 * deserves the normal sign-in page, not an opaque 404.
 */
class BlockPublicRegistration
{
    private const BLOCKED_ROUTES = ['register', 'register.store', 'setup.index', 'setup.store'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::BLOCKED_ROUTES)) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
