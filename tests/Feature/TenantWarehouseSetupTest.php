<?php

namespace Tests\Feature;

use App\Livewire\TenantCreate;
use App\Livewire\TenantIndex;
use App\Livewire\WarehouseCreate;
use App\Livewire\WarehouseIndex;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantWarehouseSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_tenant_succeeds(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(TenantCreate::class)
            ->set('code', 'XYZ')
            ->set('name', 'XYZ Corp')
            ->set('contactEmail', 'ops@example.com')
            ->call('save')
            ->assertRedirect(route('setup.tenants.index'));

        $this->assertDatabaseHas('tenants', [
            'code' => 'XYZ',
            'name' => 'XYZ Corp',
            'contact_email' => 'ops@example.com',
            'status' => 'active',
        ]);
    }

    public function test_create_tenant_rejects_duplicate_code(): void
    {
        Tenant::factory()->create(['code' => 'ABC']);

        Livewire::actingAs($this->internalUser())
            ->test(TenantCreate::class)
            ->set('code', 'abc')
            ->set('name', 'Another ABC')
            ->call('save')
            ->assertHasErrors(['code']);
    }

    public function test_create_tenant_uppercases_code(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(TenantCreate::class)
            ->set('code', 'xyz')
            ->set('name', 'XYZ Corp')
            ->call('save')
            ->assertRedirect(route('setup.tenants.index'));

        $this->assertDatabaseHas('tenants', ['code' => 'XYZ']);
    }

    public function test_create_warehouse_uppercases_code_and_country_code(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(WarehouseCreate::class)
            ->set('code', 'jp-osk-01')
            ->set('name', 'Osaka Warehouse')
            ->set('countryCode', 'jp')
            ->set('timezone', 'Asia/Tokyo')
            ->call('save')
            ->assertRedirect(route('setup.warehouses.index'));

        $this->assertDatabaseHas('warehouses', [
            'code' => 'JP-OSK-01',
            'country_code' => 'JP',
        ]);
    }

    public function test_toggle_tenant_status(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(TenantIndex::class)
            ->call('toggleStatus', $tenant->id);

        $this->assertSame('inactive', $tenant->refresh()->status);

        Livewire::actingAs($this->internalUser())
            ->test(TenantIndex::class)
            ->call('toggleStatus', $tenant->id);

        $this->assertSame('active', $tenant->refresh()->status);
    }

    public function test_create_warehouse_succeeds(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(WarehouseCreate::class)
            ->set('code', 'JP-OSK-01')
            ->set('name', 'Osaka Warehouse')
            ->set('countryCode', 'JP')
            ->set('timezone', 'Asia/Tokyo')
            ->set('city', 'Osaka')
            ->call('save')
            ->assertRedirect(route('setup.warehouses.index'));

        $this->assertDatabaseHas('warehouses', [
            'code' => 'JP-OSK-01',
            'name' => 'Osaka Warehouse',
            'country_code' => 'JP',
            'timezone' => 'Asia/Tokyo',
            'city' => 'Osaka',
            'status' => 'active',
        ]);
    }

    public function test_create_warehouse_rejects_invalid_timezone(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(WarehouseCreate::class)
            ->set('code', 'JP-OSK-01')
            ->set('name', 'Osaka Warehouse')
            ->set('countryCode', 'JP')
            ->set('timezone', 'Not/ATimezone')
            ->call('save')
            ->assertHasErrors(['timezone']);
    }

    public function test_create_warehouse_rejects_invalid_country_code(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(WarehouseCreate::class)
            ->set('code', 'JP-OSK-01')
            ->set('name', 'Osaka Warehouse')
            ->set('countryCode', 'JPN')
            ->set('timezone', 'Asia/Tokyo')
            ->call('save')
            ->assertHasErrors(['country_code']);
    }

    public function test_toggle_warehouse_status(): void
    {
        $warehouse = Warehouse::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseIndex::class)
            ->call('toggleStatus', $warehouse->id);

        $this->assertSame('inactive', $warehouse->refresh()->status);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseIndex::class)
            ->call('toggleStatus', $warehouse->id);

        $this->assertSame('active', $warehouse->refresh()->status);
    }

    public function test_non_internal_user_cannot_access_setup_pages(): void
    {
        $user = $this->tenantUser();

        $this->actingAs($user)->get('/setup/tenants')->assertForbidden();
        $this->actingAs($user)->get('/setup/tenants/create')->assertForbidden();
        $this->actingAs($user)->get('/setup/warehouses')->assertForbidden();
        $this->actingAs($user)->get('/setup/warehouses/create')->assertForbidden();
    }

    public function test_setup_routes_render(): void
    {
        $this->actingAs($this->internalUser())->get('/setup/tenants')->assertOk()->assertSee('Tenants');
        $this->actingAs($this->internalUser())->get('/setup/tenants/create')->assertOk()->assertSee('Create Tenant');
        $this->actingAs($this->internalUser())->get('/setup/warehouses')->assertOk()->assertSee('Warehouses');
        $this->actingAs($this->internalUser())->get('/setup/warehouses/create')->assertOk()->assertSee('Create Warehouse');
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
