<?php

use Illuminate\Support\Facades\Route;
use ValheimTools\Http\Controllers\ListsController;
use ValheimTools\Http\Controllers\ModsController;
use ValheimTools\Http\Controllers\PlayersController;
use ValheimTools\Http\Controllers\SettingsController;
use ValheimTools\Http\Controllers\WorldsController;

// /api/client/servers/{server}/extensions/valheim-tools/...
Route::get('/players', [PlayersController::class, 'index'])->middleware('throttle:60,1,vh-players')->name('players');
Route::post('/players/action', [PlayersController::class, 'action'])->middleware('throttle:30,1,vh-players-action')->name('players.action');

Route::get('/lists', [ListsController::class, 'index'])->middleware('throttle:60,1,vh-lists-read')->name('lists');
Route::post('/lists/add', [ListsController::class, 'add'])->middleware('throttle:30,1,vh-lists-write')->name('lists.add');
Route::post('/lists/remove', [ListsController::class, 'remove'])->middleware('throttle:30,1,vh-lists-write')->name('lists.remove');

Route::get('/worlds', [WorldsController::class, 'show'])->middleware('throttle:60,1,vh-worlds-read')->name('worlds');
Route::post('/worlds/switch', [WorldsController::class, 'switch'])->middleware('throttle:20,1,vh-worlds')->name('worlds.switch');
Route::post('/worlds/backup', [WorldsController::class, 'backup'])->middleware('throttle:20,1,vh-worlds')->name('worlds.backup');
Route::post('/worlds/restore', [WorldsController::class, 'restore'])->middleware('throttle:20,1,vh-worlds')->name('worlds.restore');
Route::post('/worlds/backups/delete', [WorldsController::class, 'deleteBackup'])->middleware('throttle:20,1,vh-worlds')->name('worlds.backups.delete');
Route::post('/worlds/delete', [WorldsController::class, 'delete'])->middleware('throttle:20,1,vh-worlds')->name('worlds.delete');

Route::get('/mods', [ModsController::class, 'index'])->middleware('throttle:60,1,vh-mods-read')->name('mods');
Route::get('/mods/updates', [ModsController::class, 'updates'])->middleware('throttle:10,1,vh-mods-updates')->name('mods.updates');
Route::get('/mods/search', [ModsController::class, 'search'])->middleware('throttle:30,1,vh-mods-search')->name('mods.search');
Route::post('/mods/install', [ModsController::class, 'install'])->middleware('throttle:10,1,vh-mods-write')->name('mods.install');
Route::post('/mods/remove', [ModsController::class, 'remove'])->middleware('throttle:20,1,vh-mods-write')->name('mods.remove');

Route::get('/settings', [SettingsController::class, 'show'])->middleware('throttle:60,1,vh-settings')->name('settings');
Route::put('/settings', [SettingsController::class, 'update'])->middleware('throttle:20,1,vh-settings-save')->name('settings.update');
