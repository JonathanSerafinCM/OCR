<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MenuOCRController;

Route::post('/process-menu', [MenuOCRController::class, 'processMenu']);
Route::post('/dish/view', [MenuOCRController::class, 'recordDishView'])->middleware('auth:api');
