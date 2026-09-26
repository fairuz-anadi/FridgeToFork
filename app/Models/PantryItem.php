<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PantryItem extends Model
{
    use HasFactory;

    /** Items within this many days of their expiry date are "use soon". */
    public const USE_SOON_DAYS = 3;

    /**
     * Typical fridge life by aisle, used when the cook does not enter a date.
     * Cupboard staples (pantry, spices) get no automatic date.
     */
    private const SHELF_LIFE_DAYS = [
        'produce' => 5,
        'dairy' => 7,
        'meat' => 2,
        'seafood' => 2,
        'bakery' => 4,
    ];

    protected $fillable = [
        'user_id',
        'ingredient_id',
        'quantity',
        'unit',
        'expires_on',
    ];

    protected $casts = [
        'quantity' => 'float',
        'expires_on' => 'date:Y-m-d',
    ];

    protected $appends = ['days_left', 'expiry_status'];

    public static function estimatedExpiry(Ingredient $ingredient): ?string
    {
        $days = self::SHELF_LIFE_DAYS[$ingredient->aisle] ?? null;

        return $days === null ? null : Carbon::today()->addDays($days)->toDateString();
    }

    /** Whole days until the expiry date; negative once it has passed. */
    public function getDaysLeftAttribute(): ?int
    {
        return $this->expires_on === null
            ? null
            : (int) Carbon::today()->diffInDays($this->expires_on, false);
    }

    /** expired | soon | ok, or null when no date is recorded. */
    public function getExpiryStatusAttribute(): ?string
    {
        $days = $this->days_left;

        return match (true) {
            $days === null => null,
            $days < 0 => 'expired',
            $days <= self::USE_SOON_DAYS => 'soon',
            default => 'ok',
        };
    }

    public function scopeUseSoon($query)
    {
        return $query->whereNotNull('expires_on')
            ->whereBetween('expires_on', [Carbon::today()->toDateString(), Carbon::today()->addDays(self::USE_SOON_DAYS)->toDateString()]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
