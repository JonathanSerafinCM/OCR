<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DishView extends Model
{
    use HasFactory;

    protected $fillable = [
        'dish_id',
        'user_id',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function dish()
    {
        return $this->belongsTo(Menu::class, 'dish_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function userPreference()
    {
        return $this->belongsTo(UserPreference::class, 'user_id', 'user_id');
    }

    /**
     * Record a new view for a dish
     */
    public static function recordView($dishId, $userId)
    {
        return self::create([
            'dish_id' => $dishId,
            'user_id' => $userId,
            'viewed_at' => Carbon::now(),
        ]);
    }

    /**
     * Get most viewed dishes for analytics
     */
    public static function getMostViewed($limit = 10, $days = 30)
    {
        return self::select('dish_id')
            ->where('viewed_at', '>=', Carbon::now()->subDays($days))
            ->groupBy('dish_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Get user's viewing history
     */
    public static function getUserHistory($userId, $limit = 10)
    {
        return self::with('dish')
            ->where('user_id', $userId)
            ->orderBy('viewed_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public static function getPopularDishes($days = 30, $limit = 10)
    {
        return self::select('dish_id')
            ->with('dish')
            ->where('viewed_at', '>=', now()->subDays($days))
            ->groupBy('dish_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit)
            ->get()
            ->map(function ($view) {
                return [
                    'dish' => $view->dish,
                    'views' => $view->views_count,
                    'last_viewed' => $view->last_viewed
                ];
            })
            ->map(function($dish) {
                return [
                    'id' => $dish->id,
                    'name' => $dish->name,
                    'category' => $dish->category,
                    'view_count' => $dish->view_count,
                    'recommendation_score' => $dish->calculateRecommendationScore()
                ];
            });
    }

    public static function getTrendingDishes($timeframe = 24, $limit = 5)
    {
        return self::select('dish_id', DB::raw('COUNT(*) as views'))
                   ->where('created_at', '>=', now()->subHours($timeframe))
                   ->groupBy('dish_id')
                   ->orderByDesc('views')
                   ->limit($limit)
                   ->get();
    }
}