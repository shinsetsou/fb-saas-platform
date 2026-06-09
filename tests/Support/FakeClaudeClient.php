<?php

namespace Tests\Support;

use App\Services\Bot\Claude\ClaudeClient;

class FakeClaudeClient implements ClaudeClient
{
    /** @var array<int, array<string, mixed>> */
    private array $responses;

    private int $index = 0;

    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /** @param array<int, array<string, mixed>> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function createMessage(array $params): array
    {
        $this->requests[] = $params;

        $response = $this->responses[$this->index] ?? [
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => '']],
        ];
        $this->index++;

        return $response;
    }
}
