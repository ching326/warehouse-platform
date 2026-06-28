<?php

namespace Tests\Feature;

use App\Livewire\TenantCreate;
use App\Livewire\TenantEdit;
use App\Livewire\TenantIndex;
use App\Livewire\WarehouseCreate;
use App\Livewire\WarehouseEdit;
use App\Livewire\WarehouseIndex;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantWarehouseSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_tenant_succeeds(): void
    {
        $warehouse = Warehouse::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(TenantCreate::class)
            ->set('code', 'XYZ')
            ->set('name', 'XYZ Corp')
            ->set('skuNameLocale', 'zh_TW')
            ->set('defaultWarehouseId', (string) $warehouse->id)
            ->set('fulfillmentItemCodeSource', Tenant::FULFILLMENT_ITEM_CODE_SOURCE_TENANT_ITEM_CODE)
            ->set('contactEmail', 'ops@example.com')
            ->call('save')
            ->assertRedirect(route('setup.tenants.index'));

        $this->assertDatabaseHas('tenants', [
            'code' => 'XYZ',
            'name' => 'XYZ Corp',
            'contact_email' => 'ops@example.com',
            'status' => 'active',
            'sku_name_locale' => 'zh_TW',
            'stock_item_name_locale' => 'ja',
            'default_warehouse_id' => $warehouse->id,
            'fulfillment_item_code_source' => Tenant::FULFILLMENT_ITEM_CODE_SOURCE_TENANT_ITEM_CODE,
        ]);
    }

    public function test_tenant_setup_rejects_invalid_fulfillment_item_code_source(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(TenantCreate::class)
            ->set('code', 'BAD')
            ->set('name', 'Bad Source Tenant')
            ->set('skuNameLocale', 'en')
            ->set('fulfillmentItemCodeSource', 'barcode')
            ->call('save')
            ->assertHasErrors(['fulfillment_item_code_source']);
    }

    public function test_create_tenant_requires_sku_name_base_language(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(TenantCreate::class)
            ->set('code', 'XYZ')
            ->set('name', 'XYZ Corp')
            ->call('save')
            ->assertHasErrors(['sku_name_locale']);
    }

    public function test_edit_tenant_keeps_stock_item_name_base_language_fixed_to_japanese(): void
    {
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        $tenant = Tenant::factory()->create([
            'sku_name_locale' => 'en',
            'stock_item_name_locale' => 'zh_TW',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(TenantEdit::class, ['tenant' => $tenant])
            ->assertSee(__('setup.stock_item_name_locale_fixed_hint'))
            ->assertDontSee('wire:model="stockItemNameLocale"', false)
            ->set('skuNameLocale', 'zh_CN')
            ->set('defaultWarehouseId', (string) $warehouse->id)
            ->set('fulfillmentItemCodeSource', Tenant::FULFILLMENT_ITEM_CODE_SOURCE_STOCK_ITEM_CODE)
            ->call('save')
            ->assertRedirect(route('setup.tenants.index'));

        $tenant->refresh();

        $this->assertSame('zh_CN', $tenant->sku_name_locale);
        $this->assertSame('ja', $tenant->stock_item_name_locale);
        $this->assertSame($warehouse->id, $tenant->default_warehouse_id);
        $this->assertSame(Tenant::FULFILLMENT_ITEM_CODE_SOURCE_STOCK_ITEM_CODE, $tenant->fulfillment_item_code_source);
    }

    public function test_create_tenant_rejects_duplicate_code(): void
    {
        Tenant::factory()->create(['code' => 'ABC']);

        Livewire::actingAs($this->internalUser())
            ->test(TenantCreate::class)
            ->set('code', 'abc')
            ->set('name', 'Another ABC')
            ->set('skuNameLocale', 'en')
            ->call('save')
            ->assertHasErrors(['code']);
    }

    public function test_create_tenant_uppercases_code(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(TenantCreate::class)
            ->set('code', 'xyz')
            ->set('name', 'XYZ Corp')
            ->set('skuNameLocale', 'ja')
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

    public function test_edit_warehouse_can_delete_unused_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => 'DEL-WH']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseEdit::class, ['warehouse' => $warehouse])
            ->assertSee(__('setup.btn_delete'))
            ->assertDontSee(__('setup.btn_cancel'))
            ->call('delete')
            ->assertRedirect(route('setup.warehouses.index'));

        $this->assertDatabaseMissing('warehouses', [
            'id' => $warehouse->id,
        ]);
    }

    public function test_edit_warehouse_does_not_delete_referenced_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => 'USED-WH']);
        WarehouseLocation::factory()->for($warehouse)->create();

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseEdit::class, ['warehouse' => $warehouse])
            ->call('delete')
            ->assertNoRedirect();

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse->id,
        ]);
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

    public function test_other_settings_links_to_warehouses(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('setup.other-settings'))
            ->assertOk()
            ->assertSee(__('common.nav_warehouses'))
            ->assertSee('setup/warehouses', false);
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
