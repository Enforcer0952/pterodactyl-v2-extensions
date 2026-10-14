<?php

use CrashGuard\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

// /api/admin/extensions/crash-guard/...
Route::get('/servers', [AdminController::class, 'servers'])->middleware('throttle:60,1,cg-admin')->name('servers');
