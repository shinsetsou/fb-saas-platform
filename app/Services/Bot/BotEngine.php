<?php

namespace App\Services\Bot;

use App\Models\Conversation;
use App\Services\Bot\Claude\ClaudeClient;
use App\Services\Bot\Tools\ToolContext;
use App\Services\Bot\Tools\ToolRegistry;
use Throwable;

class BotEngine
{
    private const MAX_ITERATIONS = 8;

    public function __construct(
        private ClaudeClient $claude,
        private ToolRegistry $tools,
        private SystemPromptBuilder $prompts,
    ) {
    }

    public function handle(Conversation $conversation, string $userText): string
    {
        $workspace = $conversation->workspace;
        $settings = $workspace->botSetting;

        if (! $settings->is_enabled) {
            return '';
        }

        $conversation->messages()->create(['role' => 'user', 'content' => $userText]);

        $system = $this->prompts->build($settings);
        $context = new ToolContext($workspace, $conversation);

        // Build Claude message history from persisted text turns.
        $messages = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->whereNotNull('content')
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        try {
            for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
                $response = $this->claude->createMessage([
                    'model' => config('services.anthropic.model'),
                    'max_tokens' => 1024,
                    'system' => $system,
                    'messages' => $messages,
                    'tools' => $this->tools->definitions(),
                    'thinking' => ['type' => 'adaptive'],
                    'output_config' => ['effort' => 'high'],
                ]);

                $messages[] = ['role' => 'assistant', 'content' => $response['content']];

                if (($response['stop_reason'] ?? null) !== 'tool_use') {
                    // Outbound assistant text is persisted by the channel, not here.
                    return $this->extractText($response['content']);
                }

                // Execute every tool_use block, collect tool_result blocks.
                $toolResults = [];
                foreach ($response['content'] as $block) {
                    if (($block['type'] ?? null) !== 'tool_use') {
                        continue;
                    }

                    $output = $this->tools->execute($block['name'], $block['input'] ?? [], $context);

                    $conversation->messages()->create([
                        'role' => 'tool',
                        'content' => $output,
                        'tool_calls' => ['name' => $block['name'], 'input' => $block['input'] ?? []],
                    ]);

                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => $output,
                    ];
                }

                $messages[] = ['role' => 'user', 'content' => $toolResults];
            }

            // Loop guard tripped — hand off.
            $conversation->update(['status' => 'needs_human']);

            return 'Let me get a teammate to help with that.';
        } catch (Throwable $e) {
            report($e);
            $conversation->update(['status' => 'needs_human']);

            return "Sorry, I'm having trouble right now — someone will follow up shortly.";
        }
    }

    /** @param array<int, array<string, mixed>> $content */
    private function extractText(array $content): string
    {
        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            }
        }

        return trim($text);
    }
}
