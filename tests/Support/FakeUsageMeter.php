<?php

namespace Tests\Support;

use App\Models\Workspace;
use App\Services\Billing\UsageMeter;

class FakeUsageMeter implements UsageMeter
{
    /** @var array<int, array{workspace_id: int, count: int}> */
    public array $reported = [];

    public function report(Workspace $workspace, int $count = 1): void
    {
        $this->reported[] = ['workspace_id' => $workspace->id, 'count' => $count];
    }
}
