<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\OptionChainController;
use App\Http\Controllers\PositionController;

Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::get('/alerts/feed', [AlertController::class, 'feed'])->name('alerts.feed');
Route::post('/alerts/{alert}/ack', [AlertController::class, 'ack'])->name('alerts.ack');


// Option chain + SL tracking (used by the dashboard)
Route::get('/option-chain', [OptionChainController::class, 'show'])->name('chain');
Route::get('/positions', [PositionController::class, 'index'])->name('positions.index');
Route::post('/positions', [PositionController::class, 'store'])->name('positions.store');
Route::delete('/positions/{position}', [PositionController::class, 'destroy'])->name('positions.destroy');