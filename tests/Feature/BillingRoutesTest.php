<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BillingRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_requires_auth(): void
    {
        $this->get('/billing/checkout')->assertRedirect('/login');
    }

    public function test_portal_requires_auth(): void
    {
        $this->get('/billing/portal')->assertRedirect('/login');
    }

    public function test_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('billing.checkout'));
        $this->assertTrue(Route::has('billing.portal'));
    }
}
