<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticatedRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_requests_are_blocked_from_operational_routes(): void
    {
        $this->get('/')->assertForbidden();
        $this->get('/inventory')->assertForbidden();
        $this->get('/sales-orders')->assertForbidden();
        $this->get('/sales-orders/export')->assertForbidden();
        $this->post('/fulfillment-groups/tracking-import')->assertForbidden();
        $this->get('/setup/shipping-methods')->assertForbidden();
    }

    public function test_guest_livewire_mount_is_not_treated_as_internal(): void
    {
        Livewire::test(SalesOrderIndex::class)->assertForbidden();
    }
}
