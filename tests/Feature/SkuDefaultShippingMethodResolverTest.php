<?php

namespace Tests\Feature;

use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Services\SalesOrders\SkuDefaultShippingMethodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkuDefaultShippingMethodResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_sku_with_active_default_returns_winner(): void
    {
        $tenant = Tenant::factory()->create();
        $method = $this->method('yamato_nekopos');
        $sku = $this->sku($tenant, $method);

        $result = app(SkuDefaultShippingMethodResolver::class)->resolve($tenant->id, [$sku->id]);

        $this->assertSame('winner', $result['status']);
        $this->assertSame($method->id, $result['shipping_method_id']);
        $this->assertSame('yamato', $result['shipping_method']);
    }

    public function test_highest_selection_priority_wins(): void
    {
        $tenant = Tenant::factory()->create();
        $low = $this->method('yamato_nekopos', 20);
        $high = $this->method('yamato_tqb', 50);

        $result = app(SkuDefaultShippingMethodResolver::class)->resolve($tenant->id, [
            $this->sku($tenant, $low)->id,
            $this->sku($tenant, $high)->id,
        ]);

        $this->assertSame('winner', $result['status']);
        $this->assertSame($high->id, $result['shipping_method_id']);
    }

    public function test_same_method_on_multiple_lines_is_not_a_tie(): void
    {
        $tenant = Tenant::factory()->create();
        $method = $this->method('yamato_compact', 30);
        $first = $this->sku($tenant, $method);
        $second = $this->sku($tenant, $method);

        $result = app(SkuDefaultShippingMethodResolver::class)->resolve($tenant->id, [$first->id, $second->id]);

        $this->assertSame('winner', $result['status']);
        $this->assertSame($method->id, $result['shipping_method_id']);
    }

    public function test_two_distinct_methods_tie_at_highest_priority(): void
    {
        $tenant = Tenant::factory()->create();
        $first = $this->method('yamato_tqb', 40);
        $second = $this->method('sagawa_thb', 40);

        $result = app(SkuDefaultShippingMethodResolver::class)->resolve($tenant->id, [
            $this->sku($tenant, $first)->id,
            $this->sku($tenant, $second)->id,
        ]);

        $this->assertSame('tie', $result['status']);
        $this->assertNull($result['shipping_method_id']);
        $this->assertNull($result['shipping_method']);
    }

    public function test_no_default_returns_none(): void
    {
        $tenant = Tenant::factory()->create();
        $sku = $this->sku($tenant);

        $result = app(SkuDefaultShippingMethodResolver::class)->resolve($tenant->id, [$sku->id]);

        $this->assertSame('none', $result['status']);
        $this->assertNull($result['shipping_method_id']);
    }

    public function test_inactive_default_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $method = $this->method('yamato_nekopos');
        $method->update(['status' => 'inactive']);
        $sku = $this->sku($tenant, $method);

        $result = app(SkuDefaultShippingMethodResolver::class)->resolve($tenant->id, [$sku->id]);

        $this->assertSame('none', $result['status']);
    }

    public function test_cross_carrier_higher_priority_wins_and_equal_priority_ties(): void
    {
        $tenant = Tenant::factory()->create();
        $yamato = $this->method('yamato_tqb', 45);
        $sagawa = $this->method('sagawa_thb', 35);
        $yamatoSku = $this->sku($tenant, $yamato);
        $sagawaSku = $this->sku($tenant, $sagawa);

        $winner = app(SkuDefaultShippingMethodResolver::class)->resolve($tenant->id, [$yamatoSku->id, $sagawaSku->id]);
        $this->assertSame('winner', $winner['status']);
        $this->assertSame($yamato->id, $winner['shipping_method_id']);

        $sagawa->update(['selection_priority' => 45]);
        $tie = app(SkuDefaultShippingMethodResolver::class)->resolve($tenant->id, [$yamatoSku->id, $sagawaSku->id]);
        $this->assertSame('tie', $tie['status']);
    }

    public function test_sku_from_another_tenant_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $method = $this->method('yamato_tqb');
        $otherSku = $this->sku($otherTenant, $method);

        $result = app(SkuDefaultShippingMethodResolver::class)->resolve($tenant->id, [$otherSku->id]);

        $this->assertSame('none', $result['status']);
    }

    private function method(string $code, ?int $priority = null): ShippingMethod
    {
        $method = ShippingMethod::where('code', $code)->firstOrFail();

        if ($priority !== null) {
            $method->update(['selection_priority' => $priority]);
        }

        return $method->refresh();
    }

    private function sku(Tenant $tenant, ?ShippingMethod $method = null): Sku
    {
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);

        return Sku::factory()
            ->for($tenant)
            ->for($shop)
            ->for(StockItem::factory()->for($tenant)->create())
            ->create([
                'sku_type' => 'single',
                'status' => 'active',
                'default_shipping_method_id' => $method?->id,
            ]);
    }
}
