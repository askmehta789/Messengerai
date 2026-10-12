<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id', 'mid', 'direction', 'sender_type', 'text', 'attachments',
        'user_id', 'status', 'error', 'ai_provider', 'ai_model', 'input_tokens',
        'output_tokens', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'sender_type' => $this->sender_type,
            'sender_name' => $this->user?->name,
            'text' => $this->text,
            'attachments' => $this->attachments ?? [],
            'status' => $this->status,
            'error' => $this->error,
            'ai_model' => $this->ai_model,
            'sent_at' => ($this->sent_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
