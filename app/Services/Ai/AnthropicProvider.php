<?php

namespace App\Services\Ai;

use Anthropic\Client;

/** Anthropic Claude via the official PHP SDK. */
class AnthropicProvider implements AiProvider
{
    /** Models that accept server-side refusal fallbacks ("default" routing). */
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public function reply(string $system, string $context, array $messages): AiResult
    {
        $client = new Client(apiKey: $this->apiKey);

        $fallback = in_array($this->model, self::FALLBACK_MODELS, true);

        $message = $client->beta->messages->create(
            model: $this->model,
            maxTokens: 4000,
            // Stable business instructions first so they can be cached across conversations.
            system: [
                ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => $context],
            ],
            messages: $messages,
            // Short chat replies don't need deep reasoning.
            outputConfig: $this->supportsEffort() ? ['effort' => 'low'] : null,
            fallbacks: $fallback ? 'default' : null,
            betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
        );

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new AiResult(
            text: trim($text),
            provider: 'anthropic',
            model: $message->model,
            inputTokens: $message->usage->inputTokens
                + ($message->usage->cacheReadInputTokens ?? 0)
                + ($message->usage->cacheCreationInputTokens ?? 0),
            outputTokens: $message->usage->outputTokens,
            refused: $message->stopReason === 'refusal',
        );
    }

    /** Effort is supported on Claude 4.6+ generation models; older ones reject it. */
    private function supportsEffort(): bool
    {
        return (bool) preg_match('/^claude-(fable-5|opus-5|sonnet-5|haiku-5|opus-4-[5-8]|sonnet-4-6)/', $this->model);
    }
}
