<?php

namespace App\Services\Bot\Claude;

use Anthropic\Client;

class AnthropicClaudeClient implements ClaudeClient
{
    public function __construct(private Client $client)
    {
    }

    public function createMessage(array $params): array
    {
        $message = $this->client->messages->create(
            maxTokens: $params['max_tokens'],
            messages: $params['messages'],
            model: $params['model'],
            system: $params['system'] ?? null,
            tools: $params['tools'] ?? null,
            thinking: $params['thinking'] ?? null,
            outputConfig: $params['output_config'] ?? null,
        );

        return [
            'stop_reason' => $message->stopReason ?? 'end_turn',
            'content' => array_map($this->normalizeBlock(...), $message->content),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBlock(object $block): array
    {
        return match ($block->type) {
            'text' => ['type' => 'text', 'text' => $block->text],
            'tool_use' => [
                'type' => 'tool_use',
                'id' => $block->id,
                'name' => $block->name,
                'input' => $block->input,
            ],
            default => ['type' => $block->type],
        };
    }
}
