<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Setting;
use RuntimeException;

/** Builds the prompt for a conversation and asks the configured AI provider for a reply. */
class AiResponder
{
    /** The AI ends its reply with this marker when a human should take over. */
    public const HANDOFF = '[HANDOFF]';

    /** How many recent messages are sent to the AI. */
    private const HISTORY = 20;

    public const PROVIDERS = [
        'openai' => 'OpenAI (ChatGPT)',
        'gemini' => 'Google Gemini',
        'anthropic' => 'Anthropic Claude',
        'openai_compatible' => 'OpenAI-compatible (DeepSeek, Groq, OpenRouter, ...)',
    ];

    /** Suggested models per provider, shown in the settings form. */
    public const MODEL_SUGGESTIONS = [
        'openai' => ['gpt-5-mini', 'gpt-5-nano', 'gpt-5'],
        'gemini' => ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro'],
        'anthropic' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'],
        'openai_compatible' => ['deepseek-chat'],
    ];

    public static function configured(): bool
    {
        return Setting::get('ai_api_key') !== '' && Setting::get('ai_api_key') !== null;
    }

    public static function provider(): AiProvider
    {
        $key = (string) Setting::get('ai_api_key');
        if ($key === '') {
            throw new RuntimeException('No AI API key configured. Add one in Settings.');
        }

        $provider = Setting::get('ai_provider');
        $model = Setting::get('ai_model') ?: self::MODEL_SUGGESTIONS[$provider][0] ?? '';

        return match ($provider) {
            'gemini' => new GeminiProvider($key, $model),
            'anthropic' => new AnthropicProvider($key, $model),
            'openai_compatible' => new OpenAiProvider(
                $key,
                $model,
                Setting::get('ai_base_url') ?: throw new RuntimeException('Base URL is required for OpenAI-compatible providers.'),
                'openai_compatible',
            ),
            default => new OpenAiProvider($key, $model),
        };
    }

    public function reply(Conversation $conversation, ?AiProvider $provider = null): AiResult
    {
        $messages = $this->history($conversation);
        if ($messages === []) {
            throw new RuntimeException('Nothing to reply to: the last message is not from the customer.');
        }

        return ($provider ?? self::provider())->reply(
            $this->systemPrompt($conversation),
            $this->contextPrompt($conversation),
            $messages,
        );
    }

    /** Stable part of the prompt: rules plus the business and page knowledge. */
    public function systemPrompt(Conversation $conversation): string
    {
        $page = $conversation->page;
        $business = trim((string) Setting::get('ai_instructions'));
        $pageInfo = trim((string) $page->ai_instructions);
        $handoff = self::HANDOFF;
        $catalog = $this->catalog($conversation);

        return <<<PROMPT
        You are the customer support assistant for the Facebook Page "{$page->name}", replying to customers in Facebook Messenger.

        How to reply:
        - Write like a friendly, helpful shop assistant on Messenger: short, natural messages (usually 1-3 sentences). No markdown, no headings, no bullet symbols like "*" or "#".
        - Reply in the same language and script the customer uses. If they write Nepali in Roman letters (e.g. "kati ho?"), reply in Romanized Nepali; if they write in Devanagari, reply in Devanagari; if English, reply in English.
        - Use only the business information below for prices, offers, stock, delivery and policies. Never invent prices, discounts, phone numbers or promises.
        - If the customer asks something the information below does not answer, asks to talk to a person, wants to complain, or is placing a complicated order, reply politely that a team member will help them, and put {$handoff} at the very end of your message.
        - When the customer shows buying interest, guide them to the next step described in the business information (for example asking for their phone number or address).
        - When the customer asks to see a product (photo, picture, video, "photo pathaunus", "dekhaunus") and that product has photos in the product list, add [SEND_MEDIA:<product number>] at the end of your message, for example [SEND_MEDIA:12]. Its photos are then sent automatically, so do not say you cannot send photos. Use at most two of these per reply.
        - Taking orders: when the customer wants to buy, collect their full name, phone number and full delivery address (and payment method if the business offers a choice). Ask only for what is still missing. When you have all of it and the customer has confirmed, thank them and add this at the very end of your message, on one line, using product numbers from the product list:
          [ORDER: {"name": "Ram Shrestha", "phone": "98XXXXXXXX", "address": "Baneshwor, Kathmandu", "city": "Kathmandu", "payment": "cod", "items": [{"product": 12, "qty": 1}]}]
          payment is one of: cod, esewa, khalti, bank. Add the ORDER line only once per order, never before the customer confirms, and never for products that are not in the list.

        Business information and rules:
        {$business}

        Information specific to this page:
        {$pageInfo}

        Products:
        {$catalog}
        PROMPT;
    }

    /** Active products of this page (and shared ones), one line each. */
    public function catalog(Conversation $conversation): string
    {
        $lines = Product::forPage($conversation->page_id)
            ->where('active', true)
            ->with('media')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map->catalogLine();

        return $lines->isEmpty() ? '(no products saved)' : $lines->implode("\n");
    }

    /** The order the AI wrote in an [ORDER: {...}] marker, if any. */
    public static function orderRequest(string $text): ?array
    {
        if (! preg_match('/\[ORDER:\s*(\{.*\})\s*\]/s', $text, $m)) {
            return null;
        }
        $data = json_decode($m[1], true);

        return is_array($data) ? $data : null;
    }

    /**
     * Product ids the AI asked to send media for.
     *
     * @return list<int>
     */
    public static function mediaRequests(string $text): array
    {
        preg_match_all('/\[SEND_MEDIA:\s*(\d+)\s*\]/i', $text, $m);

        return array_slice(array_values(array_unique(array_map('intval', $m[1]))), 0, 2);
    }

    /** Volatile part of the prompt that changes per conversation. */
    public function contextPrompt(Conversation $conversation): string
    {
        $lines = ['Current conversation:'];
        $lines[] = '- Customer name: '.($conversation->customer_name ?: 'unknown');
        if ($conversation->ad_title || $conversation->ad_id) {
            $lines[] = '- The customer started this chat by replying to an ad: '
                .($conversation->ad_title ?: 'ad id '.$conversation->ad_id);
        }
        $lines[] = '- Current date and time: '.now()->format('l, j F Y H:i').' ('.config('app.timezone').')';

        return implode("\n", $lines);
    }

    /**
     * Recent messages as alternating user/assistant turns.
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function history(Conversation $conversation): array
    {
        $rows = $conversation->messages()
            ->where('status', 'sent')
            ->latest('id')
            ->limit(self::HISTORY)
            ->get()
            ->reverse();

        $turns = [];
        foreach ($rows as $msg) {
            $role = $msg->direction === 'in' ? 'user' : 'assistant';
            $text = trim((string) $msg->text);
            foreach ($msg->attachments ?? [] as $att) {
                $text .= ($text === '' ? '' : "\n").'['.($att['type'] ?? 'file').' attachment]';
            }
            if ($text === '') {
                continue;
            }

            // Merge consecutive messages from the same side ("hi" + "price?").
            $last = array_key_last($turns);
            if ($last !== null && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] .= "\n".$text;
            } else {
                $turns[] = ['role' => $role, 'content' => $text];
            }
        }

        // The conversation sent to the AI must start and end with the customer.
        while ($turns !== [] && $turns[0]['role'] === 'assistant') {
            array_shift($turns);
        }
        if ($turns !== [] && end($turns)['role'] !== 'user') {
            return [];
        }

        return $turns;
    }

    /** Strip the handoff marker and any stray markdown the model added. */
    public static function clean(string $text): string
    {
        $text = str_replace(self::HANDOFF, '', $text);
        $text = preg_replace('/\[SEND_MEDIA:\s*\d+\s*\]/i', '', $text);
        $text = preg_replace('/\[ORDER:\s*\{.*\}\s*\]/s', '', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);

        return trim($text);
    }
}
