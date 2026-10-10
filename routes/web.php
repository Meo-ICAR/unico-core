<?php

use Illuminate\Support\Facades\Route;
use Unico\Core\Http\Controllers\DocumentDownloadController;
use Unico\Core\Http\Controllers\SignatureWebhookController;

Route::middleware(config('unico-core.routes.middleware'))
    ->prefix(config('unico-core.routes.prefix'))
    ->group(function () {
        Route::get('documents/{document}/download', DocumentDownloadController::class)
            ->whereNumber('document')
            ->name('documents.download');
    });

if (config('unico-core.signature.webhook_enabled')) {
    Route::middleware(config('unico-core.signature.webhook_middleware'))
        ->prefix(config('unico-core.routes.prefix'))
        ->post('signature/webhook/{provider}', SignatureWebhookController::class)
        ->name('signature.webhook');
}
