<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'dietary_restrictions',
        'favorite_tags',
        'order_history',
    ];

    protected $casts = [
        'dietary_restrictions' => 'array',
        'favorite_tags' => 'array',
        'order_history' => 'array',
    ];

    /**
     * Get the user that owns the preferences.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Add an item to order history
     */
    public function addToOrderHistory($dishName)
    {
        $history = $this->order_history ?? [];
        $history[] = $dishName;
        $this->order_history = array_unique($history);
        $this->save();
    }

    /**
     * Add a dietary restriction
     */
    public function addDietaryRestriction(string $restriction)
    {
        $restrictions = $this->dietary_restrictions ?? [];
        if (!in_array($restriction, $restrictions)) {
            $restrictions[] = $restriction;
            $this->dietary_restrictions = $restrictions;
            $this->save();
        }
    }

    public static function getPopularCategories($limit = 5)
    {
        return [];
    }

    public static function getCommonRestrictions()
    {
        return [];
    }

    public static function getRecommendationsForUser($userId, $limit = 10)
    {
        $preferences = self::where('user_id', $userId)->first();
        if (!$preferences) {
            return collect();
        }

        return Menu::whereHas('views')
            ->whereNotIn('id', function($query) use ($userId) {
                $query->select('dish_id')
                    ->from('dish_views')
                    ->where('user_id', $userId);
            })
            ->when($preferences->dietary_restrictions, function($query, $restrictions) {
                $query->whereNotIn('allergens', $restrictions);
            })
            ->when($preferences->favorite_tags, function($query, $tags) {
                $query->whereIn('category', $tags);
            })
            ->limit($limit)
            ->get();
    }
}