<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI Chat Completions. Also works with any OpenAI-compatible API
 * (DeepSeek, Groq, OpenRouter, Mistral, Together, ...) by changing the base URL.
 */
class OpenAiProvider implements AiProvider
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.openai.com/v1',
        private string $name = 'openai',
    ) {}

    public function reply(string $system, string $context, array $messages): AiResult
    {
        $res = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(60)
            ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system."\n\n".$context],
                    ...$messages,
                ],
            ]);

        if ($res->failed()) {
            throw new RuntimeException("{$this->name} error: ".($res->json('error.message') ?? $res->body()));
        }

        return new AiResult(
            text: trim((string) $res->json('choices.0.message.content')),
            provider: $this->name,
            model: $this->model,
            inputTokens: $res->json('usage.prompt_tokens'),
            outputTokens: $res->json('usage.completion_tokens'),
        );
    }
}
