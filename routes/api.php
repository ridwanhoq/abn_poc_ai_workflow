<?php

use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

// Public, HMAC-signed and rate-limited webhook trigger (no session, no CSRF).
Route::post('v1/hooks/{token}', WebhookController::class)
    ->middleware('throttle:webhooks')
    ->where('token', '[A-Za-z0-9]{40}')
    ->name('webhooks.trigger');
