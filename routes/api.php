<?php

use App\Http\Controllers\AttendanceSyncController;
use Illuminate\Support\Facades\Route;

// Venue server to live site. Stateless and token-authenticated, so outside the session and CSRF middleware.
Route::middleware('throttle:60,1')->prefix('attendance-sync')->group(function () {
    Route::get('/ping', [AttendanceSyncController::class, 'ping']);
    Route::get('/snapshot', [AttendanceSyncController::class, 'snapshot']);
    Route::post('/upload', [AttendanceSyncController::class, 'upload']);
});
