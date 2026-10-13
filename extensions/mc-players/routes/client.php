<?php

use Illuminate\Support\Facades\Route;
use McPlayers\Http\Controllers\McBansController;
use McPlayers\Http\Controllers\NetworkController;

// /api/client/extensions/mc-players/...: one player across all your servers.
Route::get('/network/player', [NetworkController::class, 'player'])->middleware('throttle:30,1,mc-players-network')->name('network.player');
Route::post('/network/action', [NetworkController::class, 'action'])->middleware('throttle:10,1,mc-players-network-action')->name('network.action');
Route::get('/mcbans', [McBansController::class, 'show'])->middleware('throttle:30,1,mc-players-mcbans')->name('mcbans');
