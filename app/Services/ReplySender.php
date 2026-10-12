<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiResult;
use RuntimeException;

/** Sends a reply through Messenger and records it in the inbox. */
class ReplySender
{
    private const WINDOW_CLOSED = 'The 24-hour reply window has closed. Messenger only allows replies within 24 hours of the customer\'s last message.';

    public function __construct(private MetaGraph $graph) {}

    public function send(Conversation $conversation, string $text, string $senderType, ?int $userId = null, ?AiResult $ai = null): Message
    {
        $attrs = [
            'direction' => 'out',
            'sender_type' => $senderType,
            'text' => $text,
            'user_id' => $userId,
            'ai_provider' => $ai?->provider,
            'ai_model' => $ai?->model,
            'input_tokens' => $ai?->inputTokens,
            'output_tokens' => $ai?->outputTokens,
            'sent_at' => now(),
        ];

        return $this->deliver($conversation, $attrs, $text, function () use ($conversation, $text) {
            return $this->graph->sendText($conversation->page, $conversation->psid, $text)[0] ?? null;
        });
    }

    /** Send a photo, video or file that is already stored at a public URL. */
    public function sendMedia(Conversation $conversation, string $type, string $url, string $senderType, ?int $userId = null): Message
    {
        $attrs = [
            'direction' => 'out',
            'sender_type' => $senderType,
            'attachments' => [['type' => $type, 'url' => $url]],
            'user_id' => $userId,
            'sent_at' => now(),
        ];

        return $this->deliver($conversation, $attrs, '['.$type.']', function () use ($conversation, $type, $url) {
            return $this->graph->sendAttachment($conversation->page, $conversation->psid, $type, $url);
        });
    }

    /** @param callable(): ?string $send returns the Messenger message id */
    private function deliver(Conversation $conversation, array $attrs, string $preview, callable $send): Message
    {
        if (! $conversation->withinReplyWindow()) {
            return $conversation->messages()->create($attrs + ['status' => 'failed', 'error' => self::WINDOW_CLOSED]);
        }

        try {
            $mid = $send();
        } catch (RuntimeException $e) {
            return $conversation->messages()->create($attrs + ['status' => 'failed', 'error' => $e->getMessage()]);
        }

        $message = $conversation->messages()->create($attrs + ['mid' => $mid, 'status' => 'sent']);

        $conversation->update([
            'last_message_text' => $preview,
            'last_message_from' => $attrs['sender_type'],
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);

        return $message;
    }
}
