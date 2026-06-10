<?php

namespace App\Services\Billing;

use App\Models\Workspace;

class StripeUsageMeter implements UsageMeter
{
    public function report(Workspace $workspace, int $count = 1): void
    {
        $workspace->reportMeterEvent(config('services.stripe.meter_event'), $count);
    }
}
