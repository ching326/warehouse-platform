<?php

namespace Tests\Feature;

use App\Livewire\TenantTeam;
use App\Livewire\UserCreate;
use App\Livewire\UserEdit;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_admin_can_open_users_index(): void
    {
        $this->actingAs($this->internalAdmin())
            ->get(route('setup.users.index'))
            ->assertOk()
            ->assertSee(__('users.index_title'));
    }

    public function test_warehouse_staff_and_tenant_user_cannot_open_users_index(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->warehouseStaff())
            ->get(route('setup.users.index'))
            ->assertForbidden();

        $this->actingAs($this->tenantUser($tenant, TenantUser::ROLE_ADMIN))
            ->get(route('setup.users.index'))
            ->assertForbidden();
    }

    public function test_create_internal_user_requires_role(): void
    {
        Livewire::actingAs($this->internalAdmin())
            ->test(UserCreate::class)
            ->set('name', 'Ops User')
            ->set('email', 'ops-new@example.test')
            ->set('userType', User::TYPE_INTERNAL)
            ->set('internalRole', '')
            ->call('save')
            ->assertHasErrors(['internalRole']);
    }

    public function test_create_tenant_user_leaves_users_role_null_and_requires_membership(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->internalAdmin())
            ->test(UserCreate::class)
            ->set('name', 'Tenant User')
            ->set('email', 'tenant-new@example.test')
            ->set('userType', User::TYPE_TENANT)
            ->set('tenantId', '')
            ->call('save')
            ->assertHasErrors(['tenantId']);

        Livewire::actingAs($this->internalAdmin())
            ->test(UserCreate::class)
            ->set('name', 'Tenant User')
            ->set('email', 'tenant-new@example.test')
            ->set('userType', User::TYPE_TENANT)
            ->set('tenantId', (string) $tenant->id)
            ->set('tenantRole', TenantUser::ROLE_ADMIN)
            ->call('save')
            ->assertSet('tempPassword', fn ($password) => is_string($password) && $password !== '');

        $user = User::where('email', 'tenant-new@example.test')->firstOrFail();

        $this->assertSame(User::TYPE_TENANT, $user->user_type);
        $this->assertNull($user->role);
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantUser::ROLE_ADMIN,
            'status' => TenantUser::STATUS_ACTIVE,
        ]);
    }

    public function test_internal_admin_can_set_internal_role_admin_to_warehouse(): void
    {
        $actingAdmin = $this->internalAdmin();
        $targetAdmin = $this->internalAdmin(['email' => 'target-admin@example.test']);

        Livewire::actingAs($actingAdmin)
            ->test(UserEdit::class, ['user' => $targetAdmin])
            ->set('internalRole', User::ROLE_WAREHOUSE_STAFF)
            ->call('saveInternal')
            ->assertHasNoErrors();

        $this->assertSame(User::ROLE_WAREHOUSE_STAFF, $targetAdmin->refresh()->role);
    }

    public function test_cannot_demote_or_deactivate_last_internal_admin(): void
    {
        $admin = $this->internalAdmin();

        Livewire::actingAs($admin)
            ->test(UserEdit::class, ['user' => $admin])
            ->set('internalRole', User::ROLE_WAREHOUSE_STAFF)
            ->call('saveInternal')
            ->assertHasErrors(['internalRole']);

        Livewire::actingAs($admin)
            ->test(UserEdit::class, ['user' => $admin])
            ->set('isActive', false)
            ->call('saveInternal')
            ->assertHasErrors(['internalRole']);

        $this->assertSame(User::ROLE_INTERNAL_ADMIN, $admin->refresh()->role);
        $this->assertTrue($admin->is_active);
    }

    public function test_internal_admin_can_add_tenant_membership_and_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'user_type' => User::TYPE_TENANT,
            'role' => null,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->internalAdmin())
            ->test(UserEdit::class, ['user' => $user])
            ->set('addTenantId', (string) $tenant->id)
            ->set('addTenantRole', TenantUser::ROLE_STAFF)
            ->call('addMembership')
            ->assertHasNoErrors();

        $this->assertTrue($user->isTenantStaffFor($tenant->id));
    }

    public function test_internal_admin_can_deactivate_user_unless_last_tenant_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->tenantUser($tenant, TenantUser::ROLE_ADMIN);

        Livewire::actingAs($this->internalAdmin())
            ->test(UserEdit::class, ['user' => $user])
            ->set('isActive', false)
            ->call('saveTenantAccount')
            ->assertHasErrors(['membership']);

        $this->assertTrue($user->refresh()->is_active);

        $this->tenantUser($tenant, TenantUser::ROLE_ADMIN, ['email' => 'second-admin@example.test']);

        Livewire::actingAs($this->internalAdmin())
            ->test(UserEdit::class, ['user' => $user])
            ->set('isActive', false)
            ->call('saveTenantAccount')
            ->assertHasNoErrors();

        $this->assertFalse($user->refresh()->is_active);
    }

    public function test_tenant_admin_can_open_team_and_staff_cannot(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'TEAM']);

        $this->actingAs($this->tenantUser($tenant, TenantUser::ROLE_ADMIN))
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee(__('users.team_title'));

        $this->actingAs($this->tenantUser($tenant, TenantUser::ROLE_STAFF, ['email' => 'staff@example.test']))
            ->get(route('team.index'))
            ->assertForbidden();
    }

    public function test_tenant_admin_sees_only_own_admin_tenants(): void
    {
        $adminTenant = Tenant::factory()->create(['code' => 'ADMINONLY']);
        $staffTenant = Tenant::factory()->create(['code' => 'STAFFONLY']);
        $user = $this->tenantUser($adminTenant, TenantUser::ROLE_ADMIN);

        TenantUser::factory()->create([
            'tenant_id' => $staffTenant->id,
            'user_id' => $user->id,
            'role' => TenantUser::ROLE_STAFF,
            'status' => TenantUser::STATUS_ACTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(TenantTeam::class)
            ->assertSet('tenantId', (string) $adminTenant->id)
            ->assertDontSee('STAFFONLY');
    }

    public function test_tenant_admin_can_set_member_role_within_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->tenantUser($tenant, TenantUser::ROLE_ADMIN);
        $member = $this->tenantUser($tenant, TenantUser::ROLE_STAFF, ['email' => 'member@example.test']);
        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $member->id)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(TenantTeam::class)
            ->call('setMembershipRole', $membership->id, TenantUser::ROLE_ADMIN)
            ->assertHasNoErrors();

        $this->assertTrue($member->isTenantAdminFor($tenant->id));
    }

    public function test_tenant_admin_cannot_manage_other_tenant_members(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $admin = $this->tenantUser($tenant, TenantUser::ROLE_ADMIN);
        Livewire::actingAs($admin)
            ->test(TenantTeam::class)
            ->set('tenantId', (string) $otherTenant->id)
            ->assertForbidden();

        $this->assertFalse($admin->isTenantAdminFor($otherTenant->id));
    }

    public function test_tenant_admin_add_existing_attaches_or_reactivates_membership(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->tenantUser($tenant, TenantUser::ROLE_ADMIN);
        $existing = User::factory()->create([
            'email' => 'existing@example.test',
            'user_type' => User::TYPE_TENANT,
            'role' => null,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(TenantTeam::class)
            ->set('existingEmail', 'existing@example.test')
            ->set('existingRole', TenantUser::ROLE_STAFF)
            ->call('attachExisting')
            ->assertHasNoErrors();

        $this->assertTrue($existing->isTenantStaffFor($tenant->id));

        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $existing->id)->firstOrFail();
        $membership->update(['status' => TenantUser::STATUS_INACTIVE]);

        Livewire::actingAs($admin)
            ->test(TenantTeam::class)
            ->set('existingEmail', 'existing@example.test')
            ->set('existingRole', TenantUser::ROLE_ADMIN)
            ->call('attachExisting')
            ->assertHasNoErrors();

        $this->assertSame(1, TenantUser::where('tenant_id', $tenant->id)->where('user_id', $existing->id)->count());
        $this->assertTrue($existing->isTenantAdminFor($tenant->id));
    }

    public function test_tenant_admin_add_existing_internal_email_is_rejected_generically(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->tenantUser($tenant, TenantUser::ROLE_ADMIN);
        $internal = $this->warehouseStaff(['email' => 'internal@example.test']);

        Livewire::actingAs($admin)
            ->test(TenantTeam::class)
            ->set('existingEmail', $internal->email)
            ->call('attachExisting')
            ->assertHasErrors(['existingEmail']);
    }

    public function test_cannot_remove_last_admin_of_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->tenantUser($tenant, TenantUser::ROLE_ADMIN);
        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $admin->id)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(TenantTeam::class)
            ->call('removeMembership', $membership->id)
            ->assertHasErrors(['membership']);

        $this->assertTrue($admin->isTenantAdminFor($tenant->id));
    }

    private function internalAdmin(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'user_type' => User::TYPE_INTERNAL,
            'role' => User::ROLE_INTERNAL_ADMIN,
            'is_active' => true,
        ], $attributes));
    }

    private function warehouseStaff(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'user_type' => User::TYPE_INTERNAL,
            'role' => User::ROLE_WAREHOUSE_STAFF,
            'is_active' => true,
        ], $attributes));
    }

    private function tenantUser(Tenant $tenant, string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'user_type' => User::TYPE_TENANT,
            'role' => null,
            'is_active' => true,
        ], $attributes));

        TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => TenantUser::STATUS_ACTIVE,
        ]);

        return $user;
    }
}
