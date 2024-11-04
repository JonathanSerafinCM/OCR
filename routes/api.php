<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MenuOCRController;

Route::post('/process-menu', [MenuOCRController::class, 'processMenu']);
