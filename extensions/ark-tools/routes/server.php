<?php

use ArkTools\Http\Controllers\AdminController;
use ArkTools\Http\Controllers\ModsController;
use ArkTools\Http\Controllers\PlayersController;
use Illuminate\Support\Facades\Route;

// /api/client/servers/{server}/extensions/ark-tools/...
Route::get('/mods', [ModsController::class, 'index'])->name('mods');
Route::put('/mods', [ModsController::class, 'update'])->middleware('throttle:30,1,ark-mods')->name('mods.update');
Route::post('/mods/lookup', [ModsController::class, 'lookup'])->middleware('throttle:30,1,ark-mods')->name('mods.lookup');
Route::delete('/mods/{mod}/files', [ModsController::class, 'deleteFiles'])->middleware('throttle:30,1,ark-mods')->name('mods.files');

Route::get('/players/online', [PlayersController::class, 'online'])->middleware('throttle:60,1,ark-players')->name('players.online');
Route::get('/players/lists', [PlayersController::class, 'lists'])->name('players.lists');
Route::post('/players/action', [PlayersController::class, 'action'])->middleware('throttle:60,1,ark-players')->name('players.action');
Route::put('/players/whitelist-mode', [PlayersController::class, 'whitelistMode'])->middleware('throttle:60,1,ark-players')->name('players.whitelist-mode');

Route::post('/admin/command', [AdminController::class, 'command'])->middleware('throttle:30,1,ark-admin')->name('admin.command');
Route::get('/restart', [AdminController::class, 'restartStatus'])->name('restart');
Route::post('/restart', [AdminController::class, 'restart'])->middleware('throttle:20,1,ark-restart')->name('restart.start');
Route::delete('/restart', [AdminController::class, 'cancel'])->middleware('throttle:20,1,ark-restart')->name('restart.cancel');
Route::put('/restart/daily', [AdminController::class, 'daily'])->middleware('throttle:20,1,ark-restart')->name('restart.daily');
