<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FeedController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/feed/{digest}', [FeedController::class, 'rss']);
Route::get('/feed/{digest}/{date}', [FeedController::class, 'html'])
    ->where('date', '\d{4}-\d{2}-\d{2}');
