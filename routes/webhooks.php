<?php

declare(strict_types=1);

use CommuniQate\Laravel\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::group([
    'domain' => config('communiqate.webhook_domain'),
    'middleware' => config('communiqate.webhook_middleware', []),
], static function (): void {
    Route::post(config('communiqate.webhook_path'), WebhookController::class)
        ->name('communiqate.webhook');
});
