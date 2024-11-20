<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MenuOCRController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Menu routes
    Route::post('/menu/process', [MenuOCRController::class, 'processMenu']);
    Route::get('/menu/items', [MenuOCRController::class, 'getMenuItems']);
    Route::get('/menu/recommendations', [MenuOCRController::class, 'getRecommendations']);
    
    // User preferences
    Route::get('/preferences', [MenuOCRController::class, 'getUserPreferencesApi']);
    Route::post('/preferences', [MenuOCRController::class, 'updatePreferences']);
    
    // Interaction tracking
    Route::post('/menu/track-view', [MenuOCRController::class, 'trackDishView']);
    Route::get('/menu/popular', [MenuOCRController::class, 'getPopularDishes']);
});
