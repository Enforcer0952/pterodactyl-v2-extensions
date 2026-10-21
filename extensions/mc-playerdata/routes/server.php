<?php

use Illuminate\Support\Facades\Route;
use McPlayerData\Http\Controllers\PlayerDataController;

// /api/client/servers/{server}/extensions/mc-playerdata/...
Route::get('/players', [PlayerDataController::class, 'players'])->middleware('throttle:30,1,mcpd-players')->name('players');
Route::get('/player', [PlayerDataController::class, 'show'])->middleware('throttle:60,1,mcpd-read')->name('player');
Route::post('/player/edit', [PlayerDataController::class, 'edit'])->middleware('throttle:30,1,mcpd-edit')->name('player.edit');
