<?php

// Full replacement of upstream Scanix/Gaeld's bootstrap/providers.php, copied
// wholesale over the original at build time (see Dockerfile) rather than
// patched in place with sed, so the diff against upstream is one plain,
// reviewable file. If a GAELD_VERSION bump adds/removes an upstream
// provider, this file must be updated to match — `artisan tachly:verify-compat`
// checks at runtime that every provider listed below is actually registered,
// which catches a stale copy of this file after a bump.

use App\Domains\Migration\Providers\MigrationServiceProvider;
use App\Domains\TachlyIntegration\TachlyIntegrationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FeatureFlagServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\HttpsServiceProvider;
use App\Providers\PluginServiceProvider;

return [
    MigrationServiceProvider::class,
    AppServiceProvider::class,
    FeatureFlagServiceProvider::class,
    HorizonServiceProvider::class,
    HttpsServiceProvider::class,
    PluginServiceProvider::class,
    TachlyIntegrationServiceProvider::class,
];
