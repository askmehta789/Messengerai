<?php

namespace App\Services\Ai;

interface AiProvider
{
    /**
     * Generate the next reply.
     *
     * @param  string  $system  Stable instructions (business info, rules). Cacheable.
     * @param  string  $context  Per-conversation context (customer name, ad, date).
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages  Alternating turns, first and last are 'user'.
     */
    public function reply(string $system, string $context, array $messages): AiResult;
}
