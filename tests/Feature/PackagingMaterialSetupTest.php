<?php

namespace Tests\Feature;

use App\Livewire\PackagingMaterialCreate;
use App\Livewire\PackagingMaterialEdit;
use App\Livewire\PackagingMaterialIndex;
use App\Models\PackagingMaterial;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PackagingMaterialSetupTest extends TestCase
{
    use RefreshDatabase;

    // --- access control ---

    public function test_internal_user_can_access_packagings_index(): void
    {
        $this->actingAs($this->internalUser())
            ->get('/setup/packagings')
            ->assertOk();
    }

    public function test_internal_user_can_access_packagings_create(): void
    {
        $this->actingAs($this->internalUser())
            ->get('/setup/packagings/create')
            ->assertOk();
    }

    public function test_internal_user_can_access_packagings_edit(): void
    {
        $packaging = PackagingMaterial::factory()->create();

        $this->actingAs($this->internalUser())
            ->get("/setup/packagings/{$packaging->id}/edit")
            ->assertOk();
    }

    public function test_tenant_user_cannot_access_packagings_index(): void
    {
        $this->actingAs($this->tenantUser())
            ->get('/setup/packagings')
            ->assertForbidden();
    }

    public function test_tenant_user_cannot_access_packagings_create(): void
    {
        $this->actingAs($this->tenantUser())
            ->get('/setup/packagings/create')
            ->assertForbidden();
    }

    public function test_tenant_user_cannot_access_packagings_edit(): void
    {
        $packaging = PackagingMaterial::factory()->create();

        $this->actingAs($this->tenantUser())
            ->get("/setup/packagings/{$packaging->id}/edit")
            ->assertForbidden();
    }

    // --- tenant user cannot invoke Livewire actions ---

    public function test_tenant_user_cannot_create_packaging_via_livewire(): void
    {
        Livewire::actingAs($this->tenantUser())
            ->test(PackagingMaterialCreate::class)
            ->assertForbidden();
    }

    public function test_tenant_user_cannot_edit_packaging_via_livewire(): void
    {
        $packaging = PackagingMaterial::factory()->create();

        Livewire::actingAs($this->tenantUser())
            ->test(PackagingMaterialEdit::class, ['packaging' => $packaging])
            ->assertForbidden();
    }

    public function test_tenant_user_cannot_toggle_status_via_livewire(): void
    {
        $packaging = PackagingMaterial::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->tenantUser())
            ->test(PackagingMaterialIndex::class)
            ->assertForbidden();

        $this->assertSame('active', $packaging->refresh()->status);
    }

    // --- create / edit business logic ---

    public function test_create_packaging_succeeds(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(PackagingMaterialCreate::class)
            ->set('code', 'box-sm')
            ->set('name', 'Small Box')
            ->set('type', 'box')
            ->set('status', 'active')
            ->call('save')
            ->assertRedirect(route('setup.packagings.index'));

        $this->assertDatabaseHas('packaging_materials', [
            'code' => 'BOX-SM',
            'name' => 'Small Box',
            'type' => 'box',
            'status' => 'active',
        ]);
    }

    public function test_create_packaging_requires_type(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(PackagingMaterialCreate::class)
            ->set('code', 'X-01')
            ->set('name', 'X Packaging')
            ->set('type', '')
            ->call('save')
            ->assertHasErrors(['type']);
    }

    public function test_create_packaging_rejects_duplicate_code(): void
    {
        PackagingMaterial::factory()->create(['code' => 'DUP-01']);

        Livewire::actingAs($this->internalUser())
            ->test(PackagingMaterialCreate::class)
            ->set('code', 'dup-01')
            ->set('name', 'Duplicate')
            ->set('type', 'bag')
            ->call('save')
            ->assertHasErrors(['code']);

        $this->assertSame(1, PackagingMaterial::where('code', 'DUP-01')->count());
    }

    public function test_edit_packaging_succeeds(): void
    {
        $packaging = PackagingMaterial::factory()->create([
            'code' => 'OLD-01',
            'name' => 'Old Name',
            'type' => 'box',
            'status' => 'active',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(PackagingMaterialEdit::class, ['packaging' => $packaging])
            ->set('name', 'New Name')
            ->set('status', 'inactive')
            ->call('save')
            ->assertRedirect(route('setup.packagings.index'));

        $this->assertDatabaseHas('packaging_materials', [
            'id' => $packaging->id,
            'name' => 'New Name',
            'status' => 'inactive',
        ]);
    }

    public function test_edit_packaging_rejects_duplicate_code_on_another_record(): void
    {
        PackagingMaterial::factory()->create(['code' => 'TAKEN-01']);
        $packaging = PackagingMaterial::factory()->create(['code' => 'MINE-01']);

        Livewire::actingAs($this->internalUser())
            ->test(PackagingMaterialEdit::class, ['packaging' => $packaging])
            ->set('code', 'taken-01')
            ->call('save')
            ->assertHasErrors(['code']);
    }

    public function test_edit_packaging_allows_keeping_own_code(): void
    {
        $packaging = PackagingMaterial::factory()->create(['code' => 'KEEP-01', 'type' => 'box']);

        Livewire::actingAs($this->internalUser())
            ->test(PackagingMaterialEdit::class, ['packaging' => $packaging])
            ->set('code', 'keep-01')
            ->call('save')
            ->assertRedirect(route('setup.packagings.index'));
    }

    // --- helpers ---

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
