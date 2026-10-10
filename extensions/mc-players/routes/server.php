<?php

use Illuminate\Support\Facades\Route;
use McPlayers\Http\Controllers\PlayersController;

// /api/client/servers/{server}/extensions/mc-players/...
Route::get('/online', [PlayersController::class, 'online'])->middleware('throttle:30,1')->name('online');
Route::get('/lists', [PlayersController::class, 'lists'])->name('lists');
Route::post('/action', [PlayersController::class, 'action'])->middleware('throttle:30,1')->name('action');
