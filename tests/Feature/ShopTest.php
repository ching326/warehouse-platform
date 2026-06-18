<?php

namespace Tests\Feature;

use App\Livewire\ShopCreate;
use App\Livewire\ShopIndex;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_shop_succeeds(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(ShopCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('platform', 'amazon')
            ->set('marketplace', 'amazon_jp')
            ->set('code', 'AMZJP')
            ->set('name', 'Amazon JP')
            ->call('save')
            ->assertRedirect(route('setup.shops.index'));

        $this->assertDatabaseHas('shops', [
            'tenant_id' => $tenant->id,
            'platform' => 'amazon',
            'marketplace' => 'amazon_jp',
            'code' => 'AMZJP',
            'name' => 'Amazon JP',
            'status' => 'active',
        ]);
    }

    public function test_create_shop_uppercases_code(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(ShopCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('platform', 'amazon')
            ->set('code', 'amz-jp')
            ->set('name', 'Amazon JP')
            ->call('save')
            ->assertRedirect(route('setup.shops.index'));

        $this->assertDatabaseHas('shops', [
            'tenant_id' => $tenant->id,
            'platform' => 'amazon',
            'marketplace' => '',
            'code' => 'AMZ-JP',
        ]);
    }

    public function test_create_shop_rejects_duplicate_code_within_same_tenant_platform_marketplace(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        Shop::factory()->for($tenant)->create([
            'platform' => 'amazon',
            'marketplace' => 'amazon_jp',
            'code' => 'MAIN',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(ShopCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('platform', 'amazon')
            ->set('marketplace', 'amazon_jp')
            ->set('code', 'main')
            ->set('name', 'Amazon JP Duplicate')
            ->call('save')
            ->assertHasErrors(['code']);
    }

    public function test_create_shop_allows_same_code_for_different_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        Shop::factory()->for($tenantA)->create([
            'platform' => 'amazon',
            'marketplace' => 'amazon_jp',
            'code' => 'MAIN',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(ShopCreate::class)
            ->set('tenantId', (string) $tenantB->id)
            ->set('platform', 'amazon')
            ->set('marketplace', 'amazon_jp')
            ->set('code', 'MAIN')
            ->set('name', 'Amazon JP Tenant B')
            ->call('save')
            ->assertRedirect(route('setup.shops.index'));

        $this->assertSame(2, Shop::where('code', 'MAIN')->count());
    }

    public function test_create_shop_allows_same_code_for_same_tenant_different_platform(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        Shop::factory()->for($tenant)->create([
            'platform' => 'amazon',
            'marketplace' => 'amazon_jp',
            'code' => 'MAIN',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(ShopCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('platform', 'shopify')
            ->set('marketplace', '')
            ->set('code', 'MAIN')
            ->set('name', 'Shopify Main')
            ->call('save')
            ->assertRedirect(route('setup.shops.index'));

        $this->assertSame(2, Shop::where('tenant_id', $tenant->id)->where('code', 'MAIN')->count());
    }

    public function test_create_shop_allows_same_code_for_same_tenant_same_platform_different_marketplace(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        Shop::factory()->for($tenant)->create([
            'platform' => 'amazon',
            'marketplace' => 'amazon_jp',
            'code' => 'MAIN',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(ShopCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('platform', 'amazon')
            ->set('marketplace', 'amazon_us')
            ->set('code', 'MAIN')
            ->set('name', 'Amazon US Main')
            ->call('save')
            ->assertRedirect(route('setup.shops.index'));

        $this->assertSame(2, Shop::where('tenant_id', $tenant->id)->where('platform', 'amazon')->where('code', 'MAIN')->count());
    }

    public function test_create_shop_rejects_inactive_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'inactive']);

        Livewire::actingAs($this->internalUser())
            ->test(ShopCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('platform', 'amazon')
            ->set('code', 'MAIN')
            ->set('name', 'Inactive Tenant Shop')
            ->call('save')
            ->assertHasErrors(['tenant_id']);
    }

    public function test_create_shop_rejects_invalid_platform(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(ShopCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('platform', 'ebay')
            ->set('code', 'MAIN')
            ->set('name', 'Invalid Platform')
            ->call('save')
            ->assertHasErrors(['platform']);
    }

    public function test_toggle_shop_status(): void
    {
        $shop = Shop::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(ShopIndex::class)
            ->call('toggleStatus', $shop->id);

        $this->assertSame('inactive', $shop->refresh()->status);

        Livewire::actingAs($this->internalUser())
            ->test(ShopIndex::class)
            ->call('toggleStatus', $shop->id);

        $this->assertSame('active', $shop->refresh()->status);
    }

    public function test_non_internal_user_cannot_access_shop_pages(): void
    {
        $user = $this->tenantUser();

        $this->actingAs($user)->get('/setup/shops')->assertForbidden();
        $this->actingAs($user)->get('/setup/shops/create')->assertForbidden();
    }

    public function test_non_internal_user_cannot_call_shop_create_action(): void
    {
        $user = $this->tenantUser();

        Livewire::actingAs($user)
            ->test(ShopCreate::class)
            ->assertForbidden();

        $this->assertSame(0, Shop::count());
    }

    public function test_non_internal_user_cannot_call_shop_toggle_action(): void
    {
        $shop = Shop::factory()->create(['status' => 'active']);
        $user = $this->tenantUser();

        Livewire::actingAs($user)
            ->test(ShopIndex::class)
            ->assertForbidden();

        $this->assertSame('active', $shop->refresh()->status);
    }

    public function test_shop_routes_render(): void
    {
        Tenant::factory()->create(['status' => 'active']);

        $this->actingAs($this->internalUser())->get('/setup/shops')->assertOk()->assertSee('Shops');
        $this->actingAs($this->internalUser())->get('/setup/shops/create')->assertOk()->assertSee('Create Shop');
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    private function tenantUser(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'user_type' => 'tenant',
            'is_active' => true,
        ]);

        TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return $user;
    }
}
