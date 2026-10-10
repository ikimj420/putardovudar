<?php

use App\Http\Controllers\JavneOrganizacijeController;
use App\Http\Controllers\JavnePrilikeController;
use App\Http\Controllers\JavniVodiciController;
use App\Http\Controllers\PomocnikController;
use Illuminate\Support\Facades\Route;

Route::get('/', [JavnePrilikeController::class, 'index'])->name('pocetna');
Route::get('/prilike', [JavnePrilikeController::class, 'index'])->name('prilike.index');
Route::get('/prilike/{slug}', [JavnePrilikeController::class, 'show'])->name('prilike.show');
Route::get('/vodici', [JavniVodiciController::class, 'index'])->name('vodici.index');
Route::get('/vodici/{slug}', [JavniVodiciController::class, 'show'])->name('vodici.show');
Route::get('/organizacije', [JavneOrganizacijeController::class, 'index'])->name('organizacije.index');
Route::get('/organizacije/{slug}', [JavneOrganizacijeController::class, 'show'])->name('organizacije.show');
Route::get('/pomocnik', [PomocnikController::class, 'index'])->name('pomocnik.index');
Route::post('/pomocnik', [PomocnikController::class, 'pitaj'])->middleware('throttle:pomocnik')->name('pomocnik.pitaj');
