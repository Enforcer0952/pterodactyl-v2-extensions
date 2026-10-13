<?php

use Illuminate\Support\Facades\Route;
use McWorld\Http\Controllers\PropertiesController;
use McWorld\Http\Controllers\WorldController;

// /api/client/servers/{server}/extensions/mc-world/...
Route::get('/worlds', [WorldController::class, 'show'])->middleware('throttle:60,1,mc-world-read')->name('worlds');
Route::post('/worlds/backup', [WorldController::class, 'backup'])->middleware('throttle:10,1,mc-world-action')->name('worlds.backup');
Route::post('/worlds/restore', [WorldController::class, 'restore'])->middleware('throttle:10,1,mc-world-action')->name('worlds.restore');
Route::delete('/worlds/backups/{backup}', [WorldController::class, 'deleteBackup'])->where('backup', '[^/]+')->middleware('throttle:20,1,mc-world-action')->name('worlds.backups.delete');
Route::post('/worlds/reset', [WorldController::class, 'reset'])->middleware('throttle:10,1,mc-world-action')->name('worlds.reset');
Route::put('/worlds/active', [WorldController::class, 'switch'])->middleware('throttle:20,1,mc-world-action')->name('worlds.switch');
Route::delete('/worlds/{world}', [WorldController::class, 'destroy'])->where('world', '[^/]+')->middleware('throttle:10,1,mc-world-action')->name('worlds.destroy');

Route::get('/properties', [PropertiesController::class, 'show'])->middleware('throttle:60,1,mc-world-read')->name('properties');
Route::put('/properties', [PropertiesController::class, 'update'])->middleware('throttle:20,1,mc-world-save')->name('properties.update');
