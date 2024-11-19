<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
}