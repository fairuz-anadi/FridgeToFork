<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactSubmission extends Model
{
    use HasFactory;

    public const CATEGORIES = ['bug', 'recipe', 'account', 'suggestion', 'other'];

    public const STATUSES = ['open', 'resolved', 'archived'];

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'category',
        'message',
        'status',
        'admin_reply',
        'resolved_at',
    ];

    protected $attributes = [
        'category' => 'other',
        'status' => 'open',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
