<?php

use App\Domains\TachlyIntegration\Http\Controllers\ProvisionController;
use Illuminate\Support\Facades\Route;

// Mounted under /internal/tachly by TachlyIntegrationServiceProvider, behind
// VerifyInternalSharedSecret — never under the public /api/v1 surface.

Route::post('/organizations', [ProvisionController::class, 'store']);
Route::post('/organizations/{tachlyClubId}/rotate-token', [ProvisionController::class, 'rotateToken']);
Route::post('/organizations/{tachlyClubId}/send-login-link', [ProvisionController::class, 'sendLoginLink']);
