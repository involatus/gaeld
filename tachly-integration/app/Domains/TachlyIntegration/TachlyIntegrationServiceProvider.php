<?php

namespace App\Domains\TachlyIntegration;

use App\Domains\Invoicing\Services\InvoicePdfRenderer;
use App\Domains\TachlyIntegration\Console\Commands\ProvisionClub;
use App\Domains\TachlyIntegration\Console\Commands\VerifyCompat;
use App\Domains\TachlyIntegration\Http\Middleware\VerifyInternalSharedSecret;
use App\Domains\TachlyIntegration\Services\TachlyInvoicePdfRenderer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * First-party automation layer closing the /api/v1 gaps Tachly needs to
 * provision a club's Gäld Organization without a human in the web UI or
 * `php artisan tinker`.
 *
 * Lives entirely under this one namespace — no upstream Scanix/Gaeld file
 * is modified except the one-line append to bootstrap/providers.php that
 * registers this provider (see the Dockerfile). Routes and migrations are
 * loaded from this package's own directories, so a GAELD_VERSION bump never
 * touches this code; run `artisan tachly:verify-compat` after every bump to
 * confirm the upstream classes this package depends on haven't drifted.
 */
class TachlyIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/tachly-integration.php', 'tachly-integration');

        // TAC-228: every invoice PDF this instance generates — for every org, via
        // the public GET /api/v1/invoices/{id}/pdf (InvoicePdfApiController calls
        // the same GenerateQrInvoicePdfAction) — renders with Tachly's brand.
        // Correct here: this instance exists specifically for Tachly clubs.
        $this->app->bind(InvoicePdfRenderer::class, TachlyInvoicePdfRenderer::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Deliberately NOT the public `api.php` v1 surface (no `/v1` prefix,
        // no `auth:sanctum`, no `feature:api_access` gate) — this is a
        // separate, internal-only channel gated by a shared secret that only
        // Tachly's server and this Gäld container know.
        Route::prefix('internal/tachly')
            ->middleware(VerifyInternalSharedSecret::class)
            ->group(__DIR__.'/routes/internal.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProvisionClub::class,
                VerifyCompat::class,
            ]);
        }
    }
}
