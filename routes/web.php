<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\MenuOCRController;
use Illuminate\Support\Facades\Route;

// Rutas accesibles sin autenticación
Route::get('/', function () {
    return view('welcome');
});


// Rutas que requieren autenticación
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    // Preferencias routes
    Route::get('/preferencias', [MenuOCRController::class, 'showPreferences'])->name('preferencias');
    Route::put('/preferencias', [MenuOCRController::class, 'updatePreferences'])->name('preferencias.update');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
