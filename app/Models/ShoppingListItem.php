<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShoppingListItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ingredient_id',
        'name',
        'quantity',
        'unit',
        'needed_quantity',
        'pantry_quantity',
        'pantry_unit',
        'pantry_status',
        'aisle',
        'is_checked',
        'source',
        'recipe_titles',
    ];

    protected $casts = [
        'quantity' => 'float',
        'needed_quantity' => 'float',
        'pantry_quantity' => 'float',
        'is_checked' => 'boolean',
        'recipe_titles' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
