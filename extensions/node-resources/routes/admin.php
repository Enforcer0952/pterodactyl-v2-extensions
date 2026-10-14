<?php

use Illuminate\Support\Facades\Route;
use NodeResources\Http\Controllers\AdminController;

// /api/admin/extensions/node-resources/...
Route::get('/overview', [AdminController::class, 'overview'])->middleware('throttle:60,1,nr-overview')->name('overview');
Route::get('/alerts', [AdminController::class, 'alerts'])->middleware('throttle:60,1,nr-alerts')->name('alerts');
Route::get('/nodes', [AdminController::class, 'nodes'])->middleware('throttle:60,1,nr-nodes')->name('nodes');
Route::get('/nodes/{node}', [AdminController::class, 'node'])->whereNumber('node')->middleware('throttle:60,1,nr-node')->name('node');
Route::get('/nodes/{node}/capacity', [AdminController::class, 'capacity'])->whereNumber('node')->middleware('throttle:120,1,nr-capacity')->name('capacity');
Route::get('/nodes/{node}/history', [AdminController::class, 'history'])->whereNumber('node')->middleware('throttle:60,1,nr-history')->name('history');
Route::post('/nodes/{node}/probe', [AdminController::class, 'setupProbe'])->whereNumber('node')->middleware('throttle:10,1,nr-probe')->name('probe.setup');
Route::delete('/nodes/{node}/probe', [AdminController::class, 'removeProbe'])->whereNumber('node')->middleware('throttle:10,1,nr-probe')->name('probe.remove');
Route::post('/nodes/{node}/refresh', [AdminController::class, 'refresh'])->whereNumber('node')->middleware('throttle:12,1,nr-refresh')->name('refresh');
Route::get('/servers/{server}/capacity', [AdminController::class, 'serverCapacity'])->whereNumber('server')->middleware('throttle:120,1,nr-capacity')->name('server.capacity');
Route::post('/servers/{server}/limits', [AdminController::class, 'applyLimits'])->whereNumber('server')->middleware('throttle:20,1,nr-limits')->name('server.limits');
