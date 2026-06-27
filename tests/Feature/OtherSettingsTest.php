<?php

namespace Tests\Feature;

use App\Livewire\ProductTypeSettings;
use App\Models\ProductType;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OtherSettingsTest extends TestCase
{
    use RefreshDatabase;

    // --- access control ---

    public function test_internal_user_can_access_other_settings(): void
    {
        $this->actingAs($this->internalUser())
            ->get('/setup/other-settings')
            ->assertOk()
            ->assertSee('Product Types')
            ->assertSee('FBA Warehouse');
    }

    public function test_internal_user_can_access_product_types_page(): void
    {
        $this->actingAs($this->internalUser())
            ->get('/setup/product-types')
            ->assertOk()
            ->assertSee('Product Types');
    }

    public function test_tenant_user_cannot_access_other_settings(): void
    {
        $this->actingAs($this->tenantUser())
            ->get('/setup/other-settings')
            ->assertForbidden();

        $this->actingAs($this->tenantUser())
            ->get('/setup/product-types')
            ->assertForbidden();
    }

    public function test_tenant_user_cannot_save_via_livewire(): void
    {
        Livewire::actingAs($this->tenantUser())
            ->test(ProductTypeSettings::class)
            ->assertForbidden();

        $this->assertSame(0, ProductType::count());
    }

    // --- business logic ---

    public function test_save_creates_new_product_type(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(ProductTypeSettings::class)
            ->call('addType')
            ->set('types.0.slug', 'electronics')
            ->set('types.0.name', 'Electronics')
            ->set('types.0.sort_order', 10)
            ->set('types.0.translations.en', 'Electronics')
            ->call('save');

        $this->assertDatabaseHas('product_types', [
            'slug' => 'electronics',
            'name' => 'Electronics',
        ]);
    }

    public function test_save_updates_existing_product_type(): void
    {
        $type = ProductType::create([
            'slug' => 'old-slug',
            'name' => 'Old Name',
            'sort_order' => 10,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(ProductTypeSettings::class)
            ->set('types.0.name', 'New Name')
            ->set('types.0.translations.en', 'New Name')
            ->call('save');

        $this->assertDatabaseHas('product_types', [
            'id' => $type->id,
            'name' => 'New Name',
        ]);
    }

    public function test_removing_type_deletes_it_on_save(): void
    {
        ProductType::create(['slug' => 'to-delete', 'name' => 'To Delete', 'sort_order' => 10]);

        Livewire::actingAs($this->internalUser())
            ->test(ProductTypeSettings::class)
            ->call('removeType', 0)
            ->call('save');

        $this->assertSame(0, ProductType::count());
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
