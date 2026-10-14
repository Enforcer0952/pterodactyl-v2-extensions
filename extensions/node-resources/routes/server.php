<?php

use Illuminate\Support\Facades\Route;
use NodeResources\Http\Controllers\HistoryController;

// /api/client/servers/{server}/extensions/node-resources/...
Route::get('/history', [HistoryController::class, 'show'])->middleware('throttle:60,1,nr-server-history')->name('history');
