<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLES = [
        'admin' => 'Admin: all pages, settings, team and products',
        'agent' => 'Agent: only the chats of selected pages',
    ];

    protected $attributes = [
        'role' => 'agent',
        'active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Ids of the pages this user may see, or null for "all pages" (admins). */
    public function pageIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        return $this->pages()->pluck('pages.id')->all();
    }

    public function canAccessPage(int $pageId): bool
    {
        return $this->isAdmin() || $this->pages()->where('pages.id', $pageId)->exists();
    }
}
