<?php

use Illuminate\Support\Facades\Route;
use McVersion\Http\Controllers\VersionController;

// /api/client/servers/{server}/extensions/mc-version/...
Route::get('/', [VersionController::class, 'show'])->middleware('throttle:60,1,mc-version-read')->name('show');
Route::get('/versions', [VersionController::class, 'versions'])->middleware('throttle:60,1,mc-version-read')->name('versions');
Route::get('/plan', [VersionController::class, 'plan'])->middleware('throttle:30,1,mc-version-read')->name('plan');
Route::post('/apply', [VersionController::class, 'apply'])->middleware('throttle:10,1,mc-version-apply')->name('apply');
Route::post('/rollback', [VersionController::class, 'rollback'])->middleware('throttle:10,1,mc-version-apply')->name('rollback');
Route::put('/java', [VersionController::class, 'java'])->middleware('throttle:20,1,mc-version-java')->name('java');
