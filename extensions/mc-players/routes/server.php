<?php

use Illuminate\Support\Facades\Route;
use McPlayers\Http\Controllers\PlayersController;

// /api/client/servers/{server}/extensions/mc-players/...
Route::get('/online', [PlayersController::class, 'online'])->middleware('throttle:30,1,mc-players-online')->name('online');
Route::get('/lists', [PlayersController::class, 'lists'])->middleware('throttle:30,1,mc-players-lists')->name('lists');
Route::post('/action', [PlayersController::class, 'action'])->middleware('throttle:30,1,mc-players-action')->name('action');
