<?php

namespace Tests\Feature;

use App\Livewire\WarehouseLocationCreate;
use App\Livewire\WarehouseLocationEdit;
use App\Livewire\WarehouseLocationIndex;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WarehouseLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_location_succeeds(): void
    {
        $warehouse = Warehouse::factory()->create();

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationCreate::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('code', 'a-01')
            ->set('name', 'Alpha shelf')
            ->set('zoneType', 'receiving')
            ->set('storageUnitType', 'rack')
            ->set('note', 'Near dock')
            ->call('save')
            ->assertRedirect(route('setup.locations.index'));

        $this->assertDatabaseHas('warehouse_locations', [
            'warehouse_id' => $warehouse->id,
            'code' => 'A-01',
            'name' => 'Alpha shelf',
            'zone_type' => 'receiving',
            'storage_unit_type' => 'rack',
            'status' => 'active',
            'note' => 'Near dock',
        ]);
    }

    public function test_create_location_accepts_storage_unit_types_separately_from_zone_type(): void
    {
        $warehouse = Warehouse::factory()->create();

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationCreate::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('code', 'rack-01')
            ->set('name', 'Rack 01')
            ->set('zoneType', 'storage')
            ->set('storageUnitType', 'rack')
            ->call('save')
            ->assertRedirect(route('setup.locations.index'));

        $this->assertDatabaseHas('warehouse_locations', [
            'warehouse_id' => $warehouse->id,
            'code' => 'RACK-01',
            'zone_type' => 'storage',
            'storage_unit_type' => 'rack',
        ]);
    }

    public function test_create_rejects_duplicate_code_in_same_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create();
        WarehouseLocation::factory()->for($warehouse)->create(['code' => 'A-01']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationCreate::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('code', 'a-01')
            ->set('zoneType', 'storage')
            ->call('save')
            ->assertHasErrors(['code']);

        $this->assertSame(1, WarehouseLocation::where('code', 'A-01')->count());
    }

    public function test_create_allows_same_code_in_different_warehouse(): void
    {
        $warehouseA = Warehouse::factory()->create();
        $warehouseB = Warehouse::factory()->create();
        WarehouseLocation::factory()->for($warehouseA)->create(['code' => 'A-01']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationCreate::class)
            ->set('warehouseId', (string) $warehouseB->id)
            ->set('code', 'a-01')
            ->set('zoneType', 'storage')
            ->call('save')
            ->assertRedirect(route('setup.locations.index'));

        $this->assertSame(2, WarehouseLocation::where('code', 'A-01')->count());
    }

    public function test_create_rejects_inactive_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create(['status' => 'inactive']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationCreate::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('code', 'a-01')
            ->set('zoneType', 'storage')
            ->call('save')
            ->assertHasErrors(['warehouse_id']);

        $this->assertSame(0, WarehouseLocation::count());
    }

    public function test_toggle_status_switches_active_to_inactive(): void
    {
        $location = WarehouseLocation::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationIndex::class)
            ->call('toggleStatus', $location->id);

        $this->assertSame('inactive', $location->refresh()->status);
    }

    public function test_toggle_status_switches_inactive_to_active(): void
    {
        $location = WarehouseLocation::factory()->create(['status' => 'inactive']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationIndex::class)
            ->call('toggleStatus', $location->id);

        $this->assertSame('active', $location->refresh()->status);
    }

    public function test_non_internal_user_cannot_access_location_pages(): void
    {
        $user = $this->tenantUser();

        $this->actingAs($user)->get('/setup/locations')->assertForbidden();
        $this->actingAs($user)->get('/setup/locations/create')->assertForbidden();
    }

    public function test_location_routes_render(): void
    {
        Warehouse::factory()->create();

        $this->actingAs($this->internalUser())
            ->get('/setup/locations')
            ->assertOk()
            ->assertSee('Warehouse Locations');

        $this->actingAs($this->internalUser())
            ->get('/setup/locations/create')
            ->assertOk()
            ->assertSee('Create Location');
    }

    public function test_location_index_handles_missing_zone_type_from_legacy_data(): void
    {
        $component = new WarehouseLocationIndex;

        $this->assertSame('-', $component->zoneTypeLabel(null));
    }

    public function test_warehouse_filter_shows_only_matching_warehouse(): void
    {
        $warehouseA = Warehouse::factory()->create(['code' => 'WH-LOC-A', 'name' => 'Alpha Warehouse']);
        $warehouseB = Warehouse::factory()->create(['code' => 'WH-LOC-B', 'name' => 'Beta Warehouse']);
        WarehouseLocation::factory()->for($warehouseA)->create(['code' => 'A-01']);
        WarehouseLocation::factory()->for($warehouseB)->create(['code' => 'B-01']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationIndex::class)
            ->set('warehouseId', (string) $warehouseA->id)
            ->assertSee('A-01')
            ->assertDontSee('B-01');
    }

    public function test_zone_type_filter_shows_only_matching_zone_type(): void
    {
        WarehouseLocation::factory()->create(['code' => 'STORAGE-01', 'zone_type' => 'storage']);
        WarehouseLocation::factory()->create(['code' => 'QC-01', 'zone_type' => 'qc']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationIndex::class)
            ->set('zoneTypeFilter', 'qc')
            ->assertSee('QC-01')
            ->assertDontSee('STORAGE-01');
    }

    public function test_storage_unit_type_filter_shows_only_matching_storage_unit_type(): void
    {
        WarehouseLocation::factory()->create(['code' => 'BIN-01', 'storage_unit_type' => 'bin']);
        WarehouseLocation::factory()->create(['code' => 'CAGE-01', 'storage_unit_type' => 'cage']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationIndex::class)
            ->set('storageUnitTypeFilter', 'cage')
            ->assertSee('CAGE-01')
            ->assertDontSee('BIN-01');
    }

    public function test_status_filter_shows_only_matching_status(): void
    {
        WarehouseLocation::factory()->create(['code' => 'ENABLED-01', 'status' => 'active']);
        WarehouseLocation::factory()->create(['code' => 'DISABLED-01', 'status' => 'inactive']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationIndex::class)
            ->set('statusFilter', 'inactive')
            ->assertSee('DISABLED-01')
            ->assertDontSee('ENABLED-01');
    }

    public function test_search_filters_by_code_and_name(): void
    {
        WarehouseLocation::factory()->create(['code' => 'ALPHA-01', 'name' => 'Alpha shelf']);
        WarehouseLocation::factory()->create(['code' => 'BETA-02', 'name' => 'Beta shelf']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationIndex::class)
            ->set('search', 'ALPHA')
            ->assertSee('ALPHA-01')
            ->assertDontSee('BETA-02')
            ->set('search', 'Beta')
            ->assertSee('BETA-02')
            ->assertDontSee('ALPHA-01');
    }

    // --- WarehouseLocationEdit tests ---

    public function test_edit_location_succeeds(): void
    {
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        $location = WarehouseLocation::factory()->for($warehouse)->create([
            'code' => 'EDIT-01',
            'zone_type' => 'storage',
            'storage_unit_type' => 'bin',
            'status' => 'active',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationEdit::class, ['location' => $location])
            ->set('name', 'Updated Name')
            ->set('zoneType', 'qc')
            ->set('storageUnitType', 'cage')
            ->set('status', 'inactive')
            ->call('save')
            ->assertRedirect(route('setup.locations.index'));

        $this->assertDatabaseHas('warehouse_locations', [
            'id' => $location->id,
            'name' => 'Updated Name',
            'zone_type' => 'qc',
            'storage_unit_type' => 'cage',
            'status' => 'inactive',
        ]);
    }

    public function test_edit_location_rejects_inactive_warehouse(): void
    {
        $activeWarehouse = Warehouse::factory()->create(['status' => 'active']);
        $inactiveWarehouse = Warehouse::factory()->create(['status' => 'inactive']);
        $location = WarehouseLocation::factory()->for($activeWarehouse)->create([
            'code' => 'EDIT-02',
            'zone_type' => 'storage',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationEdit::class, ['location' => $location])
            ->set('warehouseId', (string) $inactiveWarehouse->id)
            ->call('save')
            ->assertHasErrors(['warehouse_id']);

        $this->assertSame($activeWarehouse->id, $location->refresh()->warehouse_id);
    }

    public function test_edit_location_hides_other_inactive_warehouses(): void
    {
        $activeWarehouse = Warehouse::factory()->create([
            'code' => 'ACTIVE-WH',
            'name' => 'Active Warehouse',
            'status' => 'active',
        ]);
        $inactiveWarehouse = Warehouse::factory()->create([
            'code' => 'INACTIVE-WH',
            'name' => 'Inactive Warehouse',
            'status' => 'inactive',
        ]);
        $location = WarehouseLocation::factory()->for($activeWarehouse)->create();

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationEdit::class, ['location' => $location])
            ->assertSee('ACTIVE-WH')
            ->assertDontSee('INACTIVE-WH');
    }

    public function test_edit_location_hides_own_inactive_warehouse(): void
    {
        $inactiveWarehouse = Warehouse::factory()->create([
            'code' => 'OWN-INACTIVE-WH',
            'name' => 'Own Inactive Warehouse',
            'status' => 'inactive',
        ]);
        $location = WarehouseLocation::factory()->for($inactiveWarehouse)->create();

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationEdit::class, ['location' => $location])
            ->assertDontSee('>OWN-INACTIVE-WH</option>', false);
    }

    public function test_edit_location_rejects_duplicate_code_in_same_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        WarehouseLocation::factory()->for($warehouse)->create(['code' => 'TAKEN-01']);
        $location = WarehouseLocation::factory()->for($warehouse)->create(['code' => 'MINE-01', 'zone_type' => 'storage']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationEdit::class, ['location' => $location])
            ->set('code', 'taken-01')
            ->call('save')
            ->assertHasErrors(['code']);
    }

    public function test_edit_location_allows_keeping_own_code(): void
    {
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        $location = WarehouseLocation::factory()->for($warehouse)->create(['code' => 'KEEP-01', 'zone_type' => 'storage']);

        Livewire::actingAs($this->internalUser())
            ->test(WarehouseLocationEdit::class, ['location' => $location])
            ->set('code', 'keep-01')
            ->call('save')
            ->assertRedirect(route('setup.locations.index'));
    }

    public function test_non_internal_user_cannot_access_location_edit(): void
    {
        $location = WarehouseLocation::factory()->create();

        $this->actingAs($this->tenantUser())
            ->get("/setup/locations/{$location->id}/edit")
            ->assertForbidden();
    }

    public function test_tenant_user_cannot_edit_location_via_livewire(): void
    {
        $location = WarehouseLocation::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->tenantUser())
            ->test(WarehouseLocationEdit::class, ['location' => $location])
            ->assertForbidden();

        $this->assertSame('active', $location->refresh()->status);
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
