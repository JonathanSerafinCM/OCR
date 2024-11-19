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
    public function addToOrderHistory(string $itemName)
    {
        $history = $this->order_history ?? [];
        if (!in_array($itemName, $history)) {
            $history[] = $itemName;
            $this->order_history = $history;
            $this->save();
        }
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
}