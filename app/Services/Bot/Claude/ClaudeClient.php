<?php

namespace App\Services\Bot\Claude;

interface ClaudeClient
{
    /**
     * Send one Messages API request and return a normalized response.
     *
     * @param  array<string, mixed>  $params  model, max_tokens, system, messages, tools, thinking, output_config
     * @return array{stop_reason: string, content: array<int, array<string, mixed>>}
     */
    public function createMessage(array $params): array;
}
