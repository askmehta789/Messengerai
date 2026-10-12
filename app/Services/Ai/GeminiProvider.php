<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Google Gemini via the Generative Language API. */
class GeminiProvider implements AiProvider
{
    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public function reply(string $system, string $context, array $messages): AiResult
    {
        $contents = array_map(fn (array $m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $messages);

        $res = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout(60)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent", [
                'system_instruction' => ['parts' => [['text' => $system."\n\n".$context]]],
                'contents' => $contents,
            ]);

        if ($res->failed()) {
            throw new RuntimeException('gemini error: '.($res->json('error.message') ?? $res->body()));
        }

        $text = collect($res->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode('');

        return new AiResult(
            text: trim($text),
            provider: 'gemini',
            model: $this->model,
            inputTokens: $res->json('usageMetadata.promptTokenCount'),
            outputTokens: $res->json('usageMetadata.candidatesTokenCount'),
            refused: $text === '' && $res->json('promptFeedback.blockReason') !== null,
        );
    }
}
