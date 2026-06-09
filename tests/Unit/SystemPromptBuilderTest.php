<?php

namespace Tests\Unit;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Bot\SystemPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_includes_business_details(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update([
            'business_name' => 'North Star Studio',
            'business_description' => 'We book consultations.',
            'bot_tone' => 'Warm and concise.',
            'cancellation_instructions' => 'Reply to reschedule.',
        ]);

        $prompt = app(SystemPromptBuilder::class)->build($workspace->fresh()->botSetting);

        $this->assertStringContainsString('North Star Studio', $prompt);
        $this->assertStringContainsString('We book consultations.', $prompt);
        $this->assertStringContainsString('Warm and concise.', $prompt);
        $this->assertStringContainsString('Reply to reschedule.', $prompt);
    }
}
