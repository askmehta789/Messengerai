<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $fillable = [
        'page_id', 'name', 'access_token', 'picture_url', 'ai_enabled',
        'ai_instructions', 'subscribed', 'last_error',
    ];

    protected $hidden = ['access_token'];

    protected $attributes = [
        'ai_enabled' => true,
        'subscribed' => false,
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'ai_enabled' => 'boolean',
            'subscribed' => 'boolean',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Pages a user may see. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->pageIds();

        return $ids === null ? $query : $query->whereIn('id', $ids);
    }
}
