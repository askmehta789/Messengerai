<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Ai\AiResponder;
use App\Services\MetaGraph;
use App\Services\OrderService;
use App\Services\ReplySender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates and sends an AI reply for a conversation.
 *
 * Dispatched "after response" from the webhook, so it runs in the same PHP
 * process once Meta has already received its 200 OK. This needs no queue
 * worker, which suits shared hosting.
 */
class GenerateAiReply implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $conversationId) {}

    public function handle(AiResponder $responder, ReplySender $sender, MetaGraph $graph): void
    {
        ignore_user_abort(true);
        set_time_limit(120);

        $conversation = Conversation::with('page')->find($this->conversationId);
        if (! $conversation) {
            return;
        }
        $lastId = $conversation->messages()->max('id');

        $graph->typing($conversation->page, $conversation->psid);

        // Customers often send several short messages in a row ("hi", "price?").
        // Wait a moment and let only the job for the newest message answer.
        $delay = max(0, min(15, (int) Setting::get('ai_reply_delay')));
        if ($delay > 0) {
            sleep($delay);
        }
        if ($conversation->messages()->max('id') !== $lastId) {
            return;
        }

        $lock = Cache::lock("ai-reply:{$conversation->id}", 90);
        if (! $lock->get()) {
            return;
        }

        try {
            $conversation->refresh();
            if (! $conversation->aiActive() || ! $conversation->withinReplyWindow()) {
                return;
            }

            $result = $responder->reply($conversation);
            $handoff = $result->refused || $result->text === '' || str_contains($result->text, AiResponder::HANDOFF);
            $text = AiResponder::clean($result->text);
            if ($text === '') {
                $text = (string) Setting::get('ai_handoff_message');
            }

            $sender->send($conversation, $text, 'ai', null, $result);

            // The customer confirmed an order: save it as a pending order for the team to check.
            if ($payload = AiResponder::orderRequest($result->text)) {
                app(OrderService::class)->fromAi($conversation, $payload);
            }

            // Send product photos/videos the AI asked for, up to 3 per product.
            $products = Product::forPage($conversation->page_id)
                ->where('active', true)
                ->whereIn('id', AiResponder::mediaRequests($result->text))
                ->with('media')
                ->get();
            foreach ($products as $product) {
                foreach ($product->media->take(3) as $media) {
                    $sender->sendMedia($conversation, $media->type, $media->url(), 'ai');
                }
            }

            if ($handoff) {
                $conversation->update(['needs_human' => true]);
                $conversation->pauseAi((int) Setting::get('ai_pause_hours'));
            }
        } catch (Throwable $e) {
            Log::error('AI reply failed', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
            $conversation->messages()->create([
                'direction' => 'out',
                'sender_type' => 'ai',
                'status' => 'failed',
                'error' => 'AI reply failed: '.$e->getMessage(),
                'sent_at' => now(),
            ]);
            $conversation->update(['needs_human' => true]);
        } finally {
            $graph->typing($conversation->page, $conversation->psid, false);
            $lock->release();
        }
    }
}
