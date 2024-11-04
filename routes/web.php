<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MenuOCRController;

Route::get('/', function () {
    return view('welcome');
});

// Elimina o comenta la siguiente línea:
// Route::post('/process-menu', [MenuOCRController::class, 'processMenu']);
