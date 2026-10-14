<?php

use Illuminate\Support\Facades\Route;
use PalworldTools\Http\Controllers\BansController;
use PalworldTools\Http\Controllers\PlayersController;
use PalworldTools\Http\Controllers\SavesController;
use PalworldTools\Http\Controllers\SettingsController;

// /api/client/servers/{server}/extensions/palworld-tools/...
Route::get('/settings', [SettingsController::class, 'show'])->middleware('throttle:60,1,pal-settings')->name('settings');
Route::put('/settings', [SettingsController::class, 'update'])->middleware('throttle:20,1,pal-settings-save')->name('settings.update');
Route::post('/settings/rest-api', [SettingsController::class, 'enableRest'])->middleware('throttle:20,1,pal-settings-save')->name('settings.rest-api');
Route::post('/settings/apply', [SettingsController::class, 'process'])->middleware('throttle:60,1,pal-settings-apply')->name('settings.process');
Route::delete('/settings/apply', [SettingsController::class, 'cancel'])->middleware('throttle:20,1,pal-settings-save')->name('settings.cancel');

Route::get('/players', [PlayersController::class, 'show'])->middleware('throttle:60,1,pal-players')->name('players');
Route::post('/players/action', [PlayersController::class, 'action'])->middleware('throttle:30,1,pal-players-action')->name('players.action');

Route::get('/saves', [SavesController::class, 'show'])->middleware('throttle:60,1,pal-saves-read')->name('saves');
Route::post('/saves/backup', [SavesController::class, 'backup'])->middleware('throttle:20,1,pal-saves')->name('saves.backup');
Route::post('/saves/restore', [SavesController::class, 'restore'])->middleware('throttle:20,1,pal-saves')->name('saves.restore');
Route::post('/saves/restore-snapshot', [SavesController::class, 'restoreSnapshot'])->middleware('throttle:20,1,pal-saves')->name('saves.restore-snapshot');
Route::post('/saves/backups/delete', [SavesController::class, 'deleteBackup'])->middleware('throttle:20,1,pal-saves')->name('saves.backups.delete');

Route::get('/bans', [BansController::class, 'show'])->middleware('throttle:60,1,pal-bans')->name('bans');
Route::post('/bans/remove', [BansController::class, 'remove'])->middleware('throttle:20,1,pal-bans-write')->name('bans.remove');
