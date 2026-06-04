<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_bot_settings(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $this->actingAs($user)
            ->put('/bot-settings', [
                'is_enabled' => '1',
                'business_name' => 'North Star Studio',
                'business_description' => 'A studio that books consultations.',
                'default_appointment_duration_minutes' => '45',
                'booking_timezone' => 'Asia/Shanghai',
                'buffer_minutes' => '10',
                'cancellation_instructions' => 'Reply to reschedule.',
                'bot_tone' => 'Warm, concise, and professional.',
            ])
            ->assertRedirect('/bot-settings');

        $settings = $workspace->fresh()->botSetting;

        $this->assertTrue($settings->is_enabled);
        $this->assertSame('North Star Studio', $settings->business_name);
        $this->assertSame(45, (int) $settings->default_appointment_duration_minutes);
        $this->assertSame('Asia/Shanghai', $settings->booking_timezone);
        $this->assertSame(10, $settings->buffer_minutes);
    }
}
