<?php

namespace App\Services\Billing;

use App\Models\Workspace;

interface UsageMeter
{
    public function report(Workspace $workspace, int $count = 1): void;
}
