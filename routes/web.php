<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MenuOCRController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/menu', [MenuOCRController::class, 'showMenu']);
Route::get('/preferencias', [MenuOCRController::class, 'showPreferences']);
