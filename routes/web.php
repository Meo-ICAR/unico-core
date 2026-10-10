<?php

use Illuminate\Support\Facades\Route;
use Unico\Core\Http\Controllers\DocumentDownloadController;

Route::middleware(config('unico-core.routes.middleware'))
    ->prefix(config('unico-core.routes.prefix'))
    ->group(function () {
        Route::get('documents/{document}/download', DocumentDownloadController::class)
            ->whereNumber('document')
            ->name('documents.download');
    });
