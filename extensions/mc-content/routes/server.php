<?php

use Illuminate\Support\Facades\Route;
use McContent\Http\Controllers\ContentController;

// All routes live under /api/client/servers/{server}/extensions/mc-content/{kind}
// where {kind} is "plugins" or "mods".
Route::prefix('{kind}')->whereIn('kind', ['plugins', 'mods'])->group(function () {
    Route::get('/context', [ContentController::class, 'context'])->name('context');
    Route::post('/preferences', [ContentController::class, 'preferences'])->middleware('throttle:30,1')->name('preferences');
    Route::get('/search', [ContentController::class, 'search'])->name('search');
    Route::get('/projects/{project}/versions', [ContentController::class, 'versions'])
        ->where('project', '[A-Za-z0-9_-]{1,64}')
        ->name('versions');
    Route::get('/installed', [ContentController::class, 'installed'])->name('installed');
    Route::post('/install', [ContentController::class, 'install'])->middleware('throttle:30,1')->name('install');
    Route::post('/uninstall', [ContentController::class, 'uninstall'])->middleware('throttle:30,1')->name('uninstall');
});
