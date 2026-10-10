<?php

use Illuminate\Support\Facades\Route;
use McConsole\Http\Controllers\CommandsController;

// GET /api/client/servers/{server}/extensions/mc-console/commands
Route::get('/commands', CommandsController::class)->middleware('throttle:60,1')->name('commands');
