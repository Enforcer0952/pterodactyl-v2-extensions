<?php

use Illuminate\Support\Facades\Route;
use McContent\Http\Controllers\ContentController;

// Which of the two pages this server shows; asked by the navigation.
Route::get('/platform', [ContentController::class, 'platform'])->middleware('throttle:120,1,mc-content-platform')->name('platform');

// All other routes live under /api/client/servers/{server}/extensions/mc-content/{kind}
// where {kind} is "plugins" or "mods".
Route::prefix('{kind}')->whereIn('kind', ['plugins', 'mods'])->group(function () {
    Route::get('/context', [ContentController::class, 'context'])->middleware('throttle:60,1,mc-content-read')->name('context');
    Route::post('/preferences', [ContentController::class, 'preferences'])->middleware('throttle:30,1,mc-content-write')->name('preferences');
    Route::get('/search', [ContentController::class, 'search'])->middleware('throttle:60,1,mc-content-read')->name('search');
    Route::get('/projects/{project}/versions', [ContentController::class, 'versions'])
        ->where('project', '[A-Za-z0-9_-]{1,64}')
        ->middleware('throttle:60,1,mc-content-read')
        ->name('versions');
    // Reading jars through Wings is heavy, so the Installed list has its own lower limit.
    Route::get('/installed', [ContentController::class, 'installed'])->middleware('throttle:60,1,mc-content-installed')->name('installed');
    Route::post('/install', [ContentController::class, 'install'])->middleware('throttle:30,1,mc-content-write')->name('install');
    Route::post('/uninstall', [ContentController::class, 'uninstall'])->middleware('throttle:30,1,mc-content-write')->name('uninstall');
});
