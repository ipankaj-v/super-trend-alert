<?php

use App\Http\Controllers\AlertController;
use Illuminate\Support\Facades\Route;

Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::get('/alerts/feed', [AlertController::class, 'feed'])->name('alerts.feed');
Route::post('/alerts/{alert}/ack', [AlertController::class, 'ack'])->name('alerts.ack');
