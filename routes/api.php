<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MenuOCRController;


Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('api')->group(function () {
    // Menu processing
    Route::post('/menu/process', [MenuOCRController::class, 'processMenu']);
    Route::get('/menu/items', [MenuOCRController::class, 'getMenuItems']);
    Route::get('/menu/popular', [MenuOCRController::class, 'getPopularDishes']);
    Route::post('/menu/filter', [MenuOCRController::class, 'filterMenuItems']);
    
    // User preferences
    Route::get('/preferences/api', [MenuOCRController::class, 'getUserPreferencesApi']);
    Route::post('/preferences/update', [MenuOCRController::class, 'updatePreferences']);
    
    // Dish ratings
    Route::post('/dishes/{dish}/rate', [MenuOCRController::class, 'rateDish']);
    Route::get('/dishes/{dish}/rating', [MenuOCRController::class, 'getDishRating']);
    
    // Dish views
    Route::post('/dish/view', [MenuOCRController::class, 'recordDishView']);
});

