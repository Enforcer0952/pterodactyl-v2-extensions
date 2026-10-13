<?php

use Illuminate\Support\Facades\Route;
use TerrariaTools\Http\Controllers\AdminController;
use TerrariaTools\Http\Controllers\ConfigController;
use TerrariaTools\Http\Controllers\PlayersController;
use TerrariaTools\Http\Controllers\WorldsController;

// /api/client/servers/{server}/extensions/terraria-tools/...
Route::get('/players/online', [PlayersController::class, 'online'])->middleware('throttle:60,1,terra-players')->name('players.online');
Route::post('/players/action', [PlayersController::class, 'action'])->middleware('throttle:30,1,terra-players-action')->name('players.action');
Route::get('/bans', [PlayersController::class, 'bans'])->middleware('throttle:60,1,terra-bans')->name('bans');
Route::post('/bans/remove', [PlayersController::class, 'unban'])->middleware('throttle:30,1,terra-bans-write')->name('bans.remove');

Route::post('/admin/command', [AdminController::class, 'command'])->middleware('throttle:30,1,terra-admin')->name('admin.command');

Route::get('/worlds', [WorldsController::class, 'show'])->middleware('throttle:60,1,terra-worlds-read')->name('worlds');
Route::post('/worlds/switch', [WorldsController::class, 'switch'])->middleware('throttle:20,1,terra-worlds')->name('worlds.switch');
Route::post('/worlds/backup', [WorldsController::class, 'backup'])->middleware('throttle:20,1,terra-worlds')->name('worlds.backup');
Route::post('/worlds/restore', [WorldsController::class, 'restore'])->middleware('throttle:20,1,terra-worlds')->name('worlds.restore');
Route::post('/worlds/backups/delete', [WorldsController::class, 'deleteBackup'])->middleware('throttle:20,1,terra-worlds')->name('worlds.backups.delete');
Route::post('/worlds/delete', [WorldsController::class, 'delete'])->middleware('throttle:20,1,terra-worlds')->name('worlds.delete');
Route::post('/worlds/new', [WorldsController::class, 'create'])->middleware('throttle:20,1,terra-worlds')->name('worlds.new');

Route::get('/settings', [ConfigController::class, 'show'])->middleware('throttle:60,1,terra-settings')->name('settings');
Route::put('/settings', [ConfigController::class, 'update'])->middleware('throttle:20,1,terra-settings-save')->name('settings.update');
