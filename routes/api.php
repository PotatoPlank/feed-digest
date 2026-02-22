<?php

use App\Http\Controllers\DigestController;
use Illuminate\Support\Facades\Route;

Route::middleware('feed.token')->group(function (): void {
    Route::get('/digests', [DigestController::class, 'index']);
    Route::post('/digests', [DigestController::class, 'store']);
    Route::put('/digests/{digest}', [DigestController::class, 'update']);
    Route::delete('/digests/{digest}', [DigestController::class, 'destroy']);
});
