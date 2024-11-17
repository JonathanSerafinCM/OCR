<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'subcategory',
        'dish_name', // Ensure 'dish_name' is included
        'price',
        'description',
        'special_notes',
        'discount',
        'additional_details',
        'allergens'  // Add this line
    ];

    protected $casts = [
        'allergens' => 'array'
    ];
}
