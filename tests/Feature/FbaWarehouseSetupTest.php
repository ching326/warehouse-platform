<?php

namespace Tests\Feature;

use App\Livewire\FbaWarehouseCreate;
use App\Livewire\FbaWarehouseEdit;
use App\Livewire\FbaWarehouseIndex;
use App\Models\FbaWarehouse;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FbaWarehouseSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_open_index_page(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('setup.fba-warehouses.index'))
            ->assertOk()
            ->assertSee('FBA Warehouse');
    }

    public function test_tenant_user_gets_403_for_fba_warehouse_pages(): void
    {
        $user = $this->tenantUser();
        $fbaWarehouse = FbaWarehouse::factory()->create();

        $this->actingAs($user)->get(route('setup.fba-warehouses.index'))->assertForbidden();
        $this->actingAs($user)->get(route('setup.fba-warehouses.create'))->assertForbidden();
        $this->actingAs($user)->get(route('setup.fba-warehouses.edit', $fbaWarehouse))->assertForbidden();
    }

    public function test_create_page_creates_active_jp_fba_warehouse(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(FbaWarehouseCreate::class)
            ->set('countryCode', 'JP')
            ->set('code', 'NRT1')
            ->set('name', 'Amazon FBA NRT1')
            ->set('postalCode', '272-0000')
            ->set('state', 'Chiba')
            ->set('city', 'Ichikawa')
            ->set('addressLine1', 'Demo address 1-1-1')
            ->set('phone', '03-0000-0000')
            ->call('save')
            ->assertRedirect(route('setup.fba-warehouses.index'));

        $this->assertDatabaseHas('fba_warehouses', [
            'country_code' => 'JP',
            'code' => 'NRT1',
            'name' => 'Amazon FBA NRT1',
            'status' => FbaWarehouse::STATUS_ACTIVE,
        ]);
    }

    public function test_create_normalizes_code_to_uppercase(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(FbaWarehouseCreate::class)
            ->set('countryCode', 'jp')
            ->set('code', 'nrt1')
            ->set('name', 'Amazon FBA NRT1')
            ->call('save')
            ->assertRedirect(route('setup.fba-warehouses.index'));

        $this->assertDatabaseHas('fba_warehouses', [
            'country_code' => 'JP',
            'code' => 'NRT1',
        ]);
    }

    public function test_duplicate_country_and_code_is_rejected(): void
    {
        FbaWarehouse::factory()->create([
            'country_code' => 'JP',
            'code' => 'KIX2',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FbaWarehouseCreate::class)
            ->set('countryCode', 'JP')
            ->set('code', 'kix2')
            ->set('name', 'Duplicate KIX2')
            ->call('save')
            ->assertHasErrors(['code']);
    }

    public function test_edit_page_updates_address_status_and_note(): void
    {
        $fbaWarehouse = FbaWarehouse::factory()->create([
            'status' => FbaWarehouse::STATUS_ACTIVE,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FbaWarehouseEdit::class, ['fbaWarehouse' => $fbaWarehouse])
            ->set('state', 'Tokyo')
            ->set('city', 'Ota-ku')
            ->set('addressLine1', 'Updated line 1')
            ->set('status', FbaWarehouse::STATUS_INACTIVE)
            ->set('note', 'Use appointment window')
            ->call('save')
            ->assertRedirect(route('setup.fba-warehouses.index'));

        $this->assertDatabaseHas('fba_warehouses', [
            'id' => $fbaWarehouse->id,
            'state' => 'Tokyo',
            'city' => 'Ota-ku',
            'address_line1' => 'Updated line 1',
            'status' => FbaWarehouse::STATUS_INACTIVE,
            'note' => 'Use appointment window',
        ]);
    }

    public function test_index_filters_by_country(): void
    {
        FbaWarehouse::factory()->create(['country_code' => 'JP', 'code' => 'NRT1']);
        FbaWarehouse::factory()->create(['country_code' => 'US', 'code' => 'ONT8']);

        Livewire::actingAs($this->internalUser())
            ->test(FbaWarehouseIndex::class)
            ->set('countryCode', 'JP')
            ->assertSee('NRT1')
            ->assertDontSee('ONT8');
    }

    public function test_index_filters_by_status(): void
    {
        FbaWarehouse::factory()->create(['code' => 'OPEN1', 'status' => FbaWarehouse::STATUS_ACTIVE]);
        FbaWarehouse::factory()->create(['code' => 'CLOSED1', 'status' => FbaWarehouse::STATUS_INACTIVE]);

        Livewire::actingAs($this->internalUser())
            ->test(FbaWarehouseIndex::class)
            ->set('statusFilter', FbaWarehouse::STATUS_INACTIVE)
            ->assertSee('CLOSED1')
            ->assertDontSee('OPEN1');
    }

    public function test_index_search_matches_code_name_address_and_phone(): void
    {
        FbaWarehouse::factory()->create([
            'code' => 'NRT1',
            'name' => 'Amazon FBA NRT1',
            'city' => 'Ichikawa',
            'address_line1' => 'Demo address',
            'phone' => '03-1111-2222',
        ]);
        FbaWarehouse::factory()->create([
            'code' => 'KIX2',
            'name' => 'Amazon FBA KIX2',
            'city' => 'Osaka',
            'address_line1' => 'Other address',
            'phone' => '06-1111-2222',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FbaWarehouseIndex::class)
            ->set('search', 'Ichikawa')
            ->assertSee('NRT1')
            ->assertDontSee('KIX2');

        Livewire::actingAs($this->internalUser())
            ->test(FbaWarehouseIndex::class)
            ->set('search', '03-1111')
            ->assertSee('NRT1')
            ->assertDontSee('KIX2');
    }

    public function test_inactive_fba_warehouse_remains_visible_with_inactive_filter(): void
    {
        FbaWarehouse::factory()->create([
            'code' => 'OLD1',
            'status' => FbaWarehouse::STATUS_INACTIVE,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FbaWarehouseIndex::class)
            ->set('statusFilter', FbaWarehouse::STATUS_INACTIVE)
            ->assertSee('OLD1');
    }

    public function test_index_has_no_delete_action(): void
    {
        FbaWarehouse::factory()->create(['code' => 'NRT1']);

        Livewire::actingAs($this->internalUser())
            ->test(FbaWarehouseIndex::class)
            ->assertSee('NRT1')
            ->assertDontSee('Delete')
            ->assertDontSee('delete');
    }

    public function test_setup_nav_contains_fba_warehouse_and_highlights_route(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('setup.fba-warehouses.index'))
            ->assertOk()
            ->assertSee('FBA Warehouse')
            ->assertSee('setup/fba-warehouses', false)
            ->assertSee('section-nav-link is-active', false);
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
