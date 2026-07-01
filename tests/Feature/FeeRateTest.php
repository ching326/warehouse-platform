<?php

namespace Tests\Feature;

use App\Livewire\FeeRateCreate;
use App\Livewire\FeeRateEdit;
use App\Livewire\FeeRateIndex;
use App\Models\FeeRate;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeeRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_fee_type_units_are_accepted(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->internalUser())
            ->test(FeeRateCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('feeType', FeeRate::TYPE_STORAGE)
            ->set('unit', FeeRate::UNIT_PER_M3_MONTH)
            ->set('rate', '125.5000')
            ->set('currency', 'jpy')
            ->set('effectiveFrom', '2026-01-01')
            ->call('save')
            ->assertRedirect(route('setup.fee-rates.index'));

        $this->assertDatabaseHas('fee_rates', [
            'tenant_id' => $tenant->id,
            'fee_type' => FeeRate::TYPE_STORAGE,
            'unit' => FeeRate::UNIT_PER_M3_MONTH,
            'rate' => 125.5000,
            'markup_pct' => null,
            'currency' => 'JPY',
        ]);
    }

    public function test_invalid_fee_type_units_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->internalUser())
            ->test(FeeRateCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('feeType', FeeRate::TYPE_STORAGE)
            ->set('unit', FeeRate::UNIT_PER_ORDER)
            ->set('rate', '100')
            ->set('effectiveFrom', '2026-01-01')
            ->call('save')
            ->assertHasErrors(['unit']);

        Livewire::actingAs($this->internalUser())
            ->test(FeeRateCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('feeType', FeeRate::TYPE_QC)
            ->set('unit', FeeRate::UNIT_PER_M3_MONTH)
            ->set('rate', '100')
            ->set('effectiveFrom', '2026-01-01')
            ->call('save')
            ->assertHasErrors(['unit']);
    }

    public function test_percent_fee_types_use_markup_pct(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->internalUser())
            ->test(FeeRateCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('feeType', FeeRate::TYPE_POSTAGE)
            ->set('unit', FeeRate::UNIT_PERCENT)
            ->set('rate', '999')
            ->set('markupPct', '12.3456')
            ->set('effectiveFrom', '2026-01-01')
            ->call('save')
            ->assertRedirect(route('setup.fee-rates.index'));

        $this->assertDatabaseHas('fee_rates', [
            'tenant_id' => $tenant->id,
            'fee_type' => FeeRate::TYPE_POSTAGE,
            'unit' => FeeRate::UNIT_PERCENT,
            'rate' => 0,
            'markup_pct' => 12.3456,
        ]);
    }

    public function test_overlapping_effective_windows_are_rejected_and_adjacent_windows_are_accepted(): void
    {
        $tenant = Tenant::factory()->create();

        FeeRate::query()->create([
            'tenant_id' => $tenant->id,
            'fee_type' => FeeRate::TYPE_HANDLING_INBOUND,
            'unit' => FeeRate::UNIT_PER_UNIT,
            'rate' => 10,
            'currency' => 'JPY',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-01-31',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FeeRateCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('feeType', FeeRate::TYPE_HANDLING_INBOUND)
            ->set('unit', FeeRate::UNIT_PER_UNIT)
            ->set('rate', '11')
            ->set('effectiveFrom', '2026-01-15')
            ->set('effectiveTo', '2026-02-15')
            ->call('save')
            ->assertHasErrors(['effectiveFrom']);

        Livewire::actingAs($this->internalUser())
            ->test(FeeRateCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('feeType', FeeRate::TYPE_HANDLING_INBOUND)
            ->set('unit', FeeRate::UNIT_PER_UNIT)
            ->set('rate', '12')
            ->set('effectiveFrom', '2026-02-01')
            ->call('save')
            ->assertRedirect(route('setup.fee-rates.index'));

        $this->assertTrue(FeeRate::query()
            ->where('tenant_id', $tenant->id)
            ->where('fee_type', FeeRate::TYPE_HANDLING_INBOUND)
            ->whereDate('effective_from', '2026-02-01')
            ->exists());
    }

    public function test_overlap_validation_ignores_current_rate_on_edit(): void
    {
        $rate = FeeRate::query()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'fee_type' => FeeRate::TYPE_QC,
            'unit' => FeeRate::UNIT_PER_UNIT,
            'rate' => 10,
            'currency' => 'JPY',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-01-31',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FeeRateEdit::class, ['feeRate' => $rate])
            ->set('rate', '15')
            ->call('save')
            ->assertRedirect(route('setup.fee-rates.index'));

        $this->assertDatabaseHas('fee_rates', [
            'id' => $rate->id,
            'rate' => 15,
        ]);
    }

    public function test_rate_resolution_returns_covering_window_for_date(): void
    {
        $tenant = Tenant::factory()->create();

        FeeRate::query()->create([
            'tenant_id' => $tenant->id,
            'fee_type' => FeeRate::TYPE_STORAGE,
            'unit' => FeeRate::UNIT_PER_UNIT_MONTH,
            'rate' => 20,
            'currency' => 'JPY',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-01-31',
        ]);
        $february = FeeRate::query()->create([
            'tenant_id' => $tenant->id,
            'fee_type' => FeeRate::TYPE_STORAGE,
            'unit' => FeeRate::UNIT_PER_UNIT_MONTH,
            'rate' => 25,
            'currency' => 'JPY',
            'effective_from' => '2026-02-01',
            'effective_to' => null,
        ]);

        $resolved = FeeRate::resolveForDate($tenant->id, FeeRate::TYPE_STORAGE, '2026-02-15');

        $this->assertTrue($february->is($resolved));
        $this->assertNull(FeeRate::resolveForDate($tenant->id, FeeRate::TYPE_QC, '2026-02-15'));
    }

    public function test_rate_resolution_prefers_newest_effective_from_if_data_overlaps(): void
    {
        $tenant = Tenant::factory()->create();

        FeeRate::query()->create([
            'tenant_id' => $tenant->id,
            'fee_type' => FeeRate::TYPE_POSTAGE,
            'unit' => FeeRate::UNIT_PERCENT,
            'rate' => 0,
            'markup_pct' => 5,
            'currency' => 'JPY',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $newest = FeeRate::query()->create([
            'tenant_id' => $tenant->id,
            'fee_type' => FeeRate::TYPE_POSTAGE,
            'unit' => FeeRate::UNIT_PERCENT,
            'rate' => 0,
            'markup_pct' => 10,
            'currency' => 'JPY',
            'effective_from' => '2026-03-01',
            'effective_to' => null,
        ]);

        $resolved = FeeRate::resolveForDate($tenant->id, FeeRate::TYPE_POSTAGE, '2026-03-15');

        $this->assertTrue($newest->is($resolved));
    }

    public function test_internal_user_can_view_fee_rate_index(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'ABC']);
        FeeRate::query()->create([
            'tenant_id' => $tenant->id,
            'fee_type' => FeeRate::TYPE_STORAGE,
            'unit' => FeeRate::UNIT_PER_M3_MONTH,
            'rate' => 10,
            'currency' => 'JPY',
            'effective_from' => '2026-01-01',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FeeRateIndex::class)
            ->assertSee('ABC')
            ->assertSee('Storage');
    }

    public function test_tenant_user_cannot_see_or_edit_fee_rates(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->tenantUser($tenantA);
        $rate = FeeRate::query()->create([
            'tenant_id' => $tenantB->id,
            'fee_type' => FeeRate::TYPE_STORAGE,
            'unit' => FeeRate::UNIT_PER_M3_MONTH,
            'rate' => 10,
            'currency' => 'JPY',
            'effective_from' => '2026-01-01',
        ]);

        $this->actingAs($user)
            ->get(route('setup.fee-rates.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('setup.fee-rates.edit', $rate))
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(FeeRateEdit::class, ['feeRate' => $rate])
            ->assertForbidden();
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    private function tenantUser(Tenant $tenant): User
    {
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
