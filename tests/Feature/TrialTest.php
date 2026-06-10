<?php

namespace Tests\Feature;

use App\Models\Trial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_active_when_trialing_and_not_expired(): void
    {
        $trial = new Trial(['status' => 'trialing', 'ends_at' => now()->addDay()]);

        $this->assertTrue($trial->isActive());
    }

    public function test_not_active_when_expired(): void
    {
        $trial = new Trial(['status' => 'trialing', 'ends_at' => now()->subDay()]);

        $this->assertFalse($trial->isActive());
    }

    public function test_not_active_when_status_not_trialing(): void
    {
        $trial = new Trial(['status' => 'expired', 'ends_at' => now()->addDay()]);

        $this->assertFalse($trial->isActive());
    }
}
