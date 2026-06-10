<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Cashier\Checkout;

class BillingController extends Controller
{
    public function checkout(Request $request): Checkout
    {
        $workspace = $request->user()->workspaces()->firstOrFail();

        return $workspace
            ->newSubscription('default', [config('services.stripe.price_base')])
            ->meteredPrice(config('services.stripe.price_metered'))
            ->checkout([
                'success_url' => route('dashboard').'?status=subscribed',
                'cancel_url' => route('dashboard'),
            ]);
    }

    public function portal(Request $request): RedirectResponse
    {
        $workspace = $request->user()->workspaces()->firstOrFail();

        return $workspace->redirectToBillingPortal(route('dashboard'));
    }
}
