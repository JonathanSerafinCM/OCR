<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MenuOCRController;


Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

    // Rutas para procesar el menu
    Route::post('/menu/process', [MenuOCRController::class, 'processMenu']);
    Route::get('/menu/items', [MenuOCRController::class, 'getMenuItems']);
    
    // Preferencias de usuario y recomendaciones
    Route::post('/preferences/update', [MenuOCRController::class, 'updatePreferences']);
    Route::get('/preferences', [MenuOCRController::class, 'showPreferences']);
    Route::get('/preferences/api', [MenuOCRController::class, 'getUserPreferencesApi']);
    Route::post('/preferences', [MenuOCRController::class, 'updatePreferences']);
    Route::get('/menu/recommendations', [MenuOCRController::class, 'getRecommendations']);
    
    // Tracking y analiticas
    Route::post('/dish/view', [MenuOCRController::class, 'recordDishView']);
    Route::get('/menu/popular', [MenuOCRController::class, 'getPopularDishes']);
    
    // Filtrado
    Route::post('/menu/filter', [MenuOCRController::class, 'filterMenuItems']);
    
    // Calificar platillos
    Route::post('/dishes/{dish}/rate', [MenuOCRController::class, 'rateDish']);
    Route::get('/dishes/{dish}/rating', [MenuOCRController::class, 'getDishRating']);

