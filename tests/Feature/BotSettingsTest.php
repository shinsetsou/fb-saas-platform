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
                'booking_hours' => [
                    'monday' => [
                        ['start' => '09:00', 'end' => '12:00'],
                        ['start' => '13:00', 'end' => '17:00'],
                    ],
                    'tuesday' => [
                        ['start' => '09:00', 'end' => '17:00'],
                    ],
                ],
            ])
            ->assertRedirect('/bot-settings');

        $settings = $workspace->fresh()->botSetting;

        $this->assertTrue($settings->is_enabled);
        $this->assertSame('North Star Studio', $settings->business_name);
        $this->assertSame(45, (int) $settings->default_appointment_duration_minutes);
        $this->assertSame('Asia/Shanghai', $settings->booking_timezone);
        $this->assertSame(10, $settings->buffer_minutes);

        // MySQL's JSON column normalizes key order on read, so we compare
        // canonicalized (sorted) arrays rather than the raw structure.
        $expectedHours = [
            'monday' => [
                ['start' => '09:00', 'end' => '12:00'],
                ['start' => '13:00', 'end' => '17:00'],
            ],
            'tuesday' => [
                ['start' => '09:00', 'end' => '17:00'],
            ],
            'wednesday' => [],
            'thursday' => [],
            'friday' => [],
            'saturday' => [],
            'sunday' => [],
        ];
        $this->assertEqualsCanonicalizing(
            $expectedHours,
            $this->ksortRecursive($settings->booking_hours),
        );
    }

    public function test_owner_can_disable_bot_and_clear_booking_hours(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $workspace->botSetting->update([
            'is_enabled' => true,
            'booking_hours' => [
                'monday' => [['start' => '09:00', 'end' => '17:00']],
            ],
        ]);

        $this->actingAs($user)
            ->put('/bot-settings', [
                'is_enabled' => '0',
                'business_name' => 'North Star Studio',
                'default_appointment_duration_minutes' => '30',
                'booking_timezone' => 'UTC',
                'buffer_minutes' => '0',
            ])
            ->assertRedirect('/bot-settings');

        $settings = $workspace->fresh()->botSetting;

        $this->assertFalse($settings->is_enabled);
        $this->assertNull($settings->booking_hours);
    }

    public function test_overlapping_booking_hours_ranges_are_rejected(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $response = $this->actingAs($user)
            ->put('/bot-settings', [
                'is_enabled' => '1',
                'business_name' => 'North Star Studio',
                'default_appointment_duration_minutes' => '30',
                'booking_timezone' => 'UTC',
                'buffer_minutes' => '0',
                'booking_hours' => [
                    'wednesday' => [
                        ['start' => '09:00', 'end' => '13:00'],
                        ['start' => '12:00', 'end' => '17:00'],
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('booking_hours');
        $this->assertNull($workspace->fresh()->botSetting->booking_hours);
    }

    private function ksortRecursive(array $array): array
    {
        ksort($array);
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->ksortRecursive($value);
            }
        }

        return $array;
    }
}
