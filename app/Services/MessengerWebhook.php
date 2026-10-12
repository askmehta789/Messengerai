<?php

namespace App\Services;

use App\Jobs\GenerateAiReply;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Turns Messenger webhook events into conversations and messages. */
class MessengerWebhook
{
    /** Conversations that received a new customer message in this request. */
    private array $needsReply = [];

    public function __construct(private MetaGraph $graph) {}

    public function handle(array $payload): void
    {
        if (($payload['object'] ?? null) !== 'page') {
            return;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            $page = Page::where('page_id', (string) ($entry['id'] ?? ''))->first();
            if (! $page) {
                continue;
            }
            foreach ($entry['messaging'] ?? [] as $event) {
                $this->event($page, $event);
            }
        }

        foreach ($this->needsReply as $conversation) {
            if ($conversation->aiActive() && $conversation->withinReplyWindow()) {
                GenerateAiReply::dispatchAfterResponse($conversation->id);
            }
        }
    }

    private function event(Page $page, array $event): void
    {
        $message = $event['message'] ?? null;
        $isEcho = (bool) ($message['is_echo'] ?? false);

        // For echoes the page is the sender and the customer is the recipient.
        $psid = (string) ($isEcho ? ($event['recipient']['id'] ?? '') : ($event['sender']['id'] ?? ''));
        if ($psid === '' || $psid === $page->page_id) {
            return;
        }

        // Meta sends milliseconds; divide as float so it also works on 32-bit PHP.
        $at = isset($event['timestamp'])
            ? Carbon::createFromTimestamp(((float) $event['timestamp']) / 1000, config('app.timezone'))
            : now();

        $conversation = $this->conversation($page, $psid);

        $referral = $event['referral'] ?? $message['referral'] ?? $event['postback']['referral'] ?? null;
        if ($referral) {
            $this->applyReferral($conversation, $referral);
        }

        if ($message && $isEcho) {
            $this->echo($conversation, $message, $at);
        } elseif ($message) {
            $this->incoming($conversation, $message['mid'] ?? null, $message['text'] ?? null, $message['attachments'] ?? [], $at);
        } elseif (isset($event['postback'])) {
            $title = $event['postback']['title'] ?? $event['postback']['payload'] ?? 'Button';
            $this->incoming($conversation, $event['postback']['mid'] ?? null, $title, [], $at);
        }

        $conversation->save();
    }

    private function conversation(Page $page, string $psid): Conversation
    {
        $conversation = Conversation::firstOrNew(['page_id' => $page->id, 'psid' => $psid]);

        if (! $conversation->exists) {
            $profile = $this->graph->customerProfile($page, $psid);
            $conversation->customer_name = $profile['name'];
            $conversation->customer_pic = $profile['pic'];
            $conversation->save();
        }
        $conversation->setRelation('page', $page);

        return $conversation;
    }

    private function incoming(Conversation $conversation, ?string $mid, ?string $text, array $attachments, Carbon $at): void
    {
        if ($mid && Message::where('mid', $mid)->exists()) {
            return; // Meta retries webhooks; ignore duplicates.
        }

        $attachments = array_map(fn (array $a) => [
            'type' => $a['type'] ?? 'file',
            'url' => $a['payload']['url'] ?? null,
        ], $attachments);

        $conversation->messages()->create([
            'mid' => $mid,
            'direction' => 'in',
            'sender_type' => 'customer',
            'text' => $text,
            'attachments' => $attachments ?: null,
            'sent_at' => $at,
        ]);

        $conversation->fill([
            'last_message_text' => $text ?: '['.($attachments[0]['type'] ?? 'attachment').']',
            'last_message_from' => 'customer',
            'last_message_at' => $at,
            'last_customer_message_at' => $at,
            'status' => 'open',
        ]);
        $conversation->unread_count++;

        $this->needsReply[$conversation->id] = $conversation;
    }

    /** A message sent by the page: either by this app (already stored) or by a person in Business Suite. */
    private function echo(Conversation $conversation, array $message, Carbon $at): void
    {
        $mid = $message['mid'] ?? null;
        if ($mid && Message::where('mid', $mid)->exists()) {
            return;
        }
        if ((string) ($message['app_id'] ?? '') === (string) config('services.meta.app_id')) {
            return; // Sent by us; the sender stores it with its mid.
        }

        $conversation->messages()->create([
            'mid' => $mid,
            'direction' => 'out',
            'sender_type' => 'business_suite',
            'text' => $message['text'] ?? null,
            'sent_at' => $at,
        ]);

        $conversation->fill([
            'last_message_text' => $message['text'] ?? '[attachment]',
            'last_message_from' => 'page',
            'last_message_at' => $at,
        ]);

        // A person replied from Meta Business Suite / the Pages app: let them handle it.
        $conversation->pauseAi((int) Setting::get('ai_pause_hours'));
    }

    private function applyReferral(Conversation $conversation, array $referral): void
    {
        if (empty($referral['ad_id'])) {
            return;
        }
        $context = $referral['ads_context_data'] ?? [];
        $conversation->ad_id = (string) $referral['ad_id'];
        $conversation->ad_title = isset($context['ad_title']) ? Str::limit($context['ad_title'], 500) : $conversation->ad_title;
        $conversation->ad_context = $context ?: $conversation->ad_context;
    }
}
