<?php

use App\Http\Controllers\JavnePrilikeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [JavnePrilikeController::class, 'index'])->name('pocetna');
Route::get('/prilike', [JavnePrilikeController::class, 'index'])->name('prilike.index');
Route::get('/prilike/{slug}', [JavnePrilikeController::class, 'show'])->name('prilike.show');
