<?php

use Illuminate\Support\Facades\Route;
use McNetwork\Http\Controllers\NetworkController;

// /api/client/servers/{server}/extensions/mc-network/...
Route::get('/network', [NetworkController::class, 'show'])->middleware('throttle:60,1,mcnet-read')->name('network');
Route::post('/network/check', [NetworkController::class, 'check'])->middleware('throttle:120,1,mcnet-check')->name('network.check');
Route::put('/network', [NetworkController::class, 'update'])->middleware('throttle:20,1,mcnet-save')->name('network.update');
Route::post('/network/restart', [NetworkController::class, 'restart'])->middleware('throttle:10,1,mcnet-restart')->name('network.restart');
