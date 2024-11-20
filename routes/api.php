<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MenuOCRController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Menu processing routes
    Route::post('/menu/process', [MenuOCRController::class, 'processMenu']);
    Route::get('/menu/items', [MenuOCRController::class, 'getMenuItems']);
    
    // User preferences and recommendations
    Route::post('/preferences/update', [MenuOCRController::class, 'updatePreferences']);
    Route::get('/preferences', [MenuOCRController::class, 'showPreferences']);
    Route::get('/preferences/api', [MenuOCRController::class, 'getUserPreferencesApi']);
    Route::post('/preferences', [MenuOCRController::class, 'updatePreferences']);
    Route::get('/menu/recommendations', [MenuOCRController::class, 'getRecommendations']);
    
    // Tracking and analytics
    Route::post('/dish/view', [MenuOCRController::class, 'recordDishView']);
    Route::get('/menu/popular', [MenuOCRController::class, 'getPopularDishes']);
    
    // Filtering
    Route::post('/menu/filter', [MenuOCRController::class, 'filterMenuItems']);
    
    // Rating endpoints
    Route::post('/dishes/{dish}/rate', [MenuOCRController::class, 'rateDish']);
    Route::get('/dishes/{dish}/rating', [MenuOCRController::class, 'getDishRating']);
});
