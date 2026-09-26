<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'google_id',
        'points',
        'is_admin',
        'skill_level',
        'dietary_preferences',
        'allergies',
        'household_size',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
        'dietary_preferences' => 'array',
        'allergies' => 'array',
        'household_size' => 'integer',
    ];

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function favorites()
    {
        return $this->belongsToMany(Recipe::class, 'favorite_recipe')
            ->withTimestamps();
    }

    public function pantryItems()
    {
        return $this->hasMany(PantryItem::class);
    }

    public function mealPlanEntries()
    {
        return $this->hasMany(MealPlanEntry::class);
    }

    public function shoppingListItems()
    {
        return $this->hasMany(ShoppingListItem::class);
    }

    public function sentTips()
    {
        return $this->hasMany(Tip::class, 'sender_id');
    }

    public function receivedTips()
    {
        return $this->hasMany(Tip::class, 'recipient_id');
    }

    public function scopeLeaderboard($query)
    {
        return $query->withCount('recipes')
            ->orderByDesc('points')
            ->orderByDesc('recipes_count')
            ->orderBy('name');
    }

    public function contactSubmissions()
    {
        return $this->hasMany(ContactSubmission::class);
    }
}
