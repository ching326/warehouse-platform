<?php

namespace Tests\Feature;

use App\Livewire\FeeRateIndex;
use App\Livewire\FulfillmentIndex;
use App\Livewire\FulfillmentPrintHistory;
use App\Livewire\StockAdjustmentCreate;
use App\Livewire\StockCountCreate;
use App\Models\CourierExportBatch;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\CourierCarrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_admin_can_access_setup_pages(): void
    {
        $this->actingAs($this->internalAdmin())
            ->get(route('setup.tenants.index'))
            ->assertOk();
    }

    public function test_warehouse_staff_cannot_access_setup_pages(): void
    {
        $this->actingAs($this->warehouseStaff())
            ->get(route('setup.tenants.index'))
            ->assertForbidden();
    }

    public function test_tenant_users_cannot_access_setup_pages(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->tenantUser($tenant, User::TENANT_ROLE_ADMIN))
            ->get(route('setup.tenants.index'))
            ->assertForbidden();

        $this->actingAs($this->tenantUser($tenant, User::TENANT_ROLE_STAFF))
            ->get(route('setup.tenants.index'))
            ->assertForbidden();
    }

    public function test_warehouse_staff_cannot_access_billing(): void
    {
        $user = $this->warehouseStaff();

        $this->actingAs($user)
            ->get(route('setup.billing.index'))
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(FeeRateIndex::class)
            ->assertForbidden();
    }

    public function test_warehouse_staff_can_open_fulfillment(): void
    {
        $this->actingAs($this->warehouseStaff())
            ->get(route('fulfillment.index'))
            ->assertOk();
    }

    public function test_tenant_user_cannot_open_fulfillment_or_mutate_stock(): void
    {
        $user = $this->tenantUser(Tenant::factory()->create(), User::TENANT_ROLE_ADMIN);

        Livewire::actingAs($user)
            ->test(FulfillmentIndex::class)
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(StockAdjustmentCreate::class)
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(StockCountCreate::class)
            ->assertForbidden();
    }

    public function test_warehouse_staff_can_download_courier_export_batch(): void
    {
        $batch = $this->courierExportBatch(Tenant::factory()->create());

        $this->actingAs($this->warehouseStaff())
            ->get(route('courier-export-batches.download', $batch))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_tenant_user_cannot_download_courier_export_batch(): void
    {
        $tenant = Tenant::factory()->create();
        $batch = $this->courierExportBatch($tenant);

        $this->actingAs($this->tenantUser($tenant, User::TENANT_ROLE_ADMIN))
            ->get(route('courier-export-batches.download', $batch))
            ->assertForbidden();
    }

    public function test_inactive_user_is_denied_even_with_role(): void
    {
        $this->actingAs(User::factory()->create([
            'user_type' => User::TYPE_INTERNAL,
            'role' => User::ROLE_INTERNAL_ADMIN,
            'is_active' => false,
        ]))
            ->get(route('inventory.index'))
            ->assertForbidden();
    }

    public function test_tenant_membership_role_is_read_from_pivot(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = User::factory()->create([
            'user_type' => User::TYPE_TENANT,
            'role' => null,
            'is_active' => true,
        ]);

        TenantUser::factory()->create([
            'tenant_id' => $tenantA->id,
            'user_id' => $user->id,
            'role' => User::TENANT_ROLE_ADMIN,
            'status' => 'active',
        ]);
        TenantUser::factory()->create([
            'tenant_id' => $tenantB->id,
            'user_id' => $user->id,
            'role' => User::TENANT_ROLE_STAFF,
            'status' => 'active',
        ]);

        $this->assertTrue($user->isTenantAdminFor($tenantA->id));
        $this->assertFalse($user->isTenantAdminFor($tenantB->id));
        $this->assertTrue($user->isTenantStaffFor($tenantB->id));
        $this->assertFalse($user->isTenantStaffFor($tenantA->id));
    }

    public function test_inactive_tenant_membership_does_not_grant_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'user_type' => User::TYPE_TENANT,
            'role' => null,
            'is_active' => true,
        ]);

        TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => User::TENANT_ROLE_ADMIN,
            'status' => 'inactive',
        ]);

        $this->assertFalse($user->isTenantAdminFor($tenant->id));
    }

    public function test_warehouse_staff_gets_internal_all_tenant_data_scope_in_v1(): void
    {
        $tenantA = Tenant::factory()->create(['code' => 'AAA']);
        $tenantB = Tenant::factory()->create(['code' => 'BBB']);
        $batchA = $this->courierExportBatch($tenantA, 'aaa.csv');
        $batchB = $this->courierExportBatch($tenantB, 'bbb.csv');

        Livewire::actingAs($this->warehouseStaff())
            ->test(FulfillmentPrintHistory::class)
            ->assertSee($batchA->file_name)
            ->assertSee($batchB->file_name);
    }

    private function internalAdmin(): User
    {
        return User::factory()->create([
            'user_type' => User::TYPE_INTERNAL,
            'role' => User::ROLE_INTERNAL_ADMIN,
            'is_active' => true,
        ]);
    }

    private function warehouseStaff(): User
    {
        return User::factory()->create([
            'user_type' => User::TYPE_INTERNAL,
            'role' => User::ROLE_WAREHOUSE_STAFF,
            'is_active' => true,
        ]);
    }

    private function tenantUser(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create([
            'user_type' => User::TYPE_TENANT,
            'role' => null,
            'is_active' => true,
        ]);

        TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);

        return $user;
    }

    private function courierExportBatch(Tenant $tenant, string $fileName = 'labels.csv'): CourierExportBatch
    {
        Storage::fake('local');
        $path = 'courier_exports/'.$fileName;
        Storage::disk('local')->put($path, 'test');

        return CourierExportBatch::query()->create([
            'tenant_id' => $tenant->id,
            'carrier' => CourierCarrier::YAMATO,
            'file_name' => $fileName,
            'disk' => 'local',
            'path' => $path,
            'order_count' => 1,
            'exported_by_user_id' => $this->internalAdmin()->id,
            'exported_at' => now(),
        ]);
    }
}
