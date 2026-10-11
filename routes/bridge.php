<?php

use Illuminate\Support\Facades\Route;
use Unico\Core\Bridge\Http\Controllers\CommandController;

/*
 * Bridge per sistemi esterni (unicobpm, app di terzi): comandi in coda con esito e stato dell'app. Attivo con
 * `unico-core.bridge.enabled`; le rotte stanno sotto /api e usano il middleware indicato in `unico-core.bridge.middleware`.
 */
Route::middleware(config('unico-core.bridge.middleware'))->prefix('api')->group(function () {
    Route::get('commands', [CommandController::class, 'index'])->name('bridge.commands.index');
    Route::get('commands/runs/{run}', [CommandController::class, 'show'])->whereNumber('run')->name('bridge.runs.show');
    Route::post('commands/{command}', [CommandController::class, 'store'])->name('bridge.commands.store');
    Route::get('bridge/health', [CommandController::class, 'health'])->name('bridge.health');
});
