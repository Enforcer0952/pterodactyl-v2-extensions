<?php

use CrashGuard\Http\Controllers\GuardController;
use Illuminate\Support\Facades\Route;

// /api/client/servers/{server}/extensions/crash-guard/...
Route::get('/', [GuardController::class, 'show'])->middleware('throttle:60,1,cg-read')->name('show');
Route::put('/', [GuardController::class, 'update'])->middleware('throttle:20,1,cg-write')->name('update');
Route::post('/clear', [GuardController::class, 'clear'])->middleware('throttle:20,1,cg-write')->name('clear');
