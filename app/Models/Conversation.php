<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'page_id', 'psid', 'customer_name', 'customer_pic', 'last_message_text',
        'last_message_from', 'last_message_at', 'last_customer_message_at',
        'unread_count', 'ai_enabled', 'ai_paused_until', 'needs_human', 'status',
        'ad_id', 'ad_title', 'ad_context', 'labels', 'notes',
    ];

    /** Labels a team can put on a conversation. */
    public const LABELS = ['Interested', 'Ordered', 'Delivered', 'Follow up', 'VIP', 'Complaint'];

    /** Mirror the column defaults so new, unsaved models behave the same as loaded ones. */
    protected $attributes = [
        'unread_count' => 0,
        'ai_enabled' => true,
        'needs_human' => false,
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_customer_message_at' => 'datetime',
            'ai_paused_until' => 'datetime',
            'ai_enabled' => 'boolean',
            'needs_human' => 'boolean',
            'ad_context' => 'array',
            'labels' => 'array',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest('id');
    }

    /** Conversations of the pages a user may see. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->pageIds();

        return $ids === null ? $query : $query->whereIn('page_id', $ids);
    }

    /** Messenger only allows free-form replies within 24 hours of the customer's last message. */
    public function withinReplyWindow(): bool
    {
        return $this->last_customer_message_at !== null
            && $this->last_customer_message_at->gt(now()->subHours(24));
    }

    /** Whether the AI is currently allowed to answer in this conversation. */
    public function aiActive(): bool
    {
        if (! $this->ai_enabled || ! $this->page->ai_enabled) {
            return false;
        }

        return $this->ai_paused_until === null || $this->ai_paused_until->isPast();
    }

    /** Pause the AI because a human took over. Hours <= 0 means until resumed manually. */
    public function pauseAi(int $hours): void
    {
        if ($hours > 0) {
            $this->ai_paused_until = now()->addHours($hours);
        } else {
            $this->ai_enabled = false;
        }
        $this->save();
    }

    public function toListArray(): array
    {
        return [
            'id' => $this->id,
            'page_id' => $this->page_id,
            'page_name' => $this->page?->name,
            'customer_name' => $this->customer_name ?: 'Facebook user',
            'customer_pic' => $this->customer_pic,
            'last_message_text' => $this->last_message_text,
            'last_message_from' => $this->last_message_from,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_customer_message_at' => $this->last_customer_message_at?->toIso8601String(),
            'unread_count' => $this->unread_count,
            'ai_enabled' => $this->ai_enabled,
            'ai_active' => $this->aiActive(),
            'ai_paused_until' => $this->ai_paused_until?->toIso8601String(),
            'needs_human' => $this->needs_human,
            'status' => $this->status,
            'ad_id' => $this->ad_id,
            'ad_title' => $this->ad_title,
            'ad_context' => $this->ad_context,
            'within_window' => $this->withinReplyWindow(),
            'labels' => $this->labels ?? [],
            'notes' => $this->notes,
            'page_picture' => $this->page?->picture_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
