<?php

use Illuminate\Support\Facades\Route;
use McCrash\Http\Controllers\CrashController;

// /api/client/servers/{server}/extensions/mc-crash/...
Route::get('/status', [CrashController::class, 'status'])->middleware('throttle:120,1,mc-crash-status')->name('status');
Route::post('/viewed', [CrashController::class, 'viewed'])->middleware('throttle:30,1,mc-crash-viewed')->name('viewed');
Route::get('/reports', [CrashController::class, 'reports'])->middleware('throttle:60,1,mc-crash-reports')->name('reports');
Route::get('/logs', [CrashController::class, 'logs'])->middleware('throttle:60,1,mc-crash-logs')->name('logs');
Route::get('/raw', [CrashController::class, 'raw'])->middleware('throttle:60,1,mc-crash-raw')->name('raw');
Route::get('/share/preview', [CrashController::class, 'preview'])->middleware('throttle:20,1,mc-crash-share-preview')->name('share.preview');
Route::post('/share', [CrashController::class, 'share'])->middleware('throttle:6,1,mc-crash-share')->name('share');
