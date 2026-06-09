<?php

namespace Tests\Unit;

use Tests\Support\FakeClaudeClient;
use Tests\TestCase;

class FakeClaudeClientTest extends TestCase
{
    public function test_returns_scripted_responses_in_order(): void
    {
        $client = new FakeClaudeClient([
            ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'check_availability', 'input' => []],
            ]],
            ['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => 'Booked!'],
            ]],
        ]);

        $first = $client->createMessage([]);
        $second = $client->createMessage([]);

        $this->assertSame('tool_use', $first['stop_reason']);
        $this->assertSame('Booked!', $second['content'][0]['text']);
    }
}
