<?php

namespace Tests\Feature;

use App\Livewire\ShopEdit;
use App\Models\AmazonSpapiConnection;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\AmazonSpapiRegion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AmazonSpapiConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_internal_user_can_see_amazon_spapi_panel_on_amazon_shop_edit_page(): void
    {
        $shop = $this->amazonShop();

        $this->actingAs($this->internalUser())
            ->get(route('setup.shops.edit', $shop))
            ->assertOk()
            ->assertSee(__('amazon_spapi.panel_title'));
    }

    public function test_panel_is_hidden_for_non_amazon_shop(): void
    {
        $shop = Shop::factory()->create(['platform' => 'shopify']);

        $this->actingAs($this->internalUser())
            ->get(route('setup.shops.edit', $shop))
            ->assertOk()
            ->assertDontSee(__('amazon_spapi.panel_title'));
    }

    public function test_tenant_user_cannot_access_or_update_amazon_settings(): void
    {
        $shop = $this->amazonShop();
        $user = $this->tenantUser();

        $this->actingAs($user)
            ->get(route('setup.shops.edit', $shop))
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(ShopEdit::class, ['shop' => $shop])
            ->assertForbidden();
    }

    public function test_can_create_connection_for_amazon_shop(): void
    {
        $shop = $this->amazonShop(['marketplace' => 'JP']);

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $shop])
            ->set('spapiSellerId', 'SELLER123')
            ->set('spapiMarketplaceId', 'A1VC38T7YXB528')
            ->set('spapiRegion', AmazonSpapiRegion::FE)
            ->set('spapiLwaClientId', 'client-id')
            ->set('spapiLwaClientSecretInput', 'client-secret')
            ->set('spapiRefreshTokenInput', 'refresh-token')
            ->set('spapiSyncEnabled', true)
            ->call('saveAmazonSettings')
            ->assertHasNoErrors()
            ->assertSet('spapiLwaClientSecretInput', '')
            ->assertSet('spapiRefreshTokenInput', '');

        $connection = AmazonSpapiConnection::firstOrFail();

        $this->assertSame($shop->tenant_id, $connection->tenant_id);
        $this->assertSame($shop->id, $connection->shop_id);
        $this->assertSame('SELLER123', $connection->seller_id);
        $this->assertSame('https://sellingpartnerapi-fe.amazon.com', $connection->endpoint);
        $this->assertSame('client-secret', $connection->lwa_client_secret);
        $this->assertSame('refresh-token', $connection->refresh_token);
        $this->assertTrue($connection->sync_enabled);
    }

    public function test_can_update_non_secret_fields_without_replacing_existing_secrets(): void
    {
        $connection = $this->connection();

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $connection->shop])
            ->set('spapiSellerId', 'SELLER-UPDATED')
            ->set('spapiSyncEnabled', false)
            ->call('saveAmazonSettings')
            ->assertHasNoErrors();

        $connection->refresh();

        $this->assertSame('SELLER-UPDATED', $connection->seller_id);
        $this->assertSame('client-secret', $connection->lwa_client_secret);
        $this->assertSame('refresh-token', $connection->refresh_token);
        $this->assertFalse($connection->sync_enabled);
    }

    public function test_can_replace_a_secret_by_entering_a_new_value(): void
    {
        $connection = $this->connection();

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $connection->shop])
            ->set('spapiLwaClientSecretInput', 'new-secret')
            ->call('saveAmazonSettings')
            ->assertHasNoErrors();

        $this->assertSame('new-secret', $connection->refresh()->lwa_client_secret);
        $this->assertSame('refresh-token', $connection->refresh_token);
    }

    public function test_secret_values_are_encrypted_in_db_and_not_rendered(): void
    {
        $connection = $this->connection();
        $raw = DB::table('amazon_spapi_connections')->where('id', $connection->id)->first();

        $this->assertNotSame('client-secret', $raw->lwa_client_secret);
        $this->assertNotSame('refresh-token', $raw->refresh_token);

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $connection->shop])
            ->assertDontSee('client-secret')
            ->assertDontSee('refresh-token')
            ->assertSet('spapiLwaClientSecretInput', '')
            ->assertSet('spapiRefreshTokenInput', '');
    }

    public function test_duplicate_connection_for_same_shop_is_updated_not_duplicated(): void
    {
        $connection = $this->connection();

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $connection->shop])
            ->set('spapiSellerId', 'SELLER-SECOND')
            ->call('saveAmazonSettings')
            ->assertHasNoErrors();

        $this->assertSame(1, AmazonSpapiConnection::where('shop_id', $connection->shop_id)->count());
        $this->assertSame('SELLER-SECOND', $connection->refresh()->seller_id);
    }

    public function test_cannot_create_connection_for_non_amazon_shop(): void
    {
        $shop = Shop::factory()->create(['platform' => 'shopify']);

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $shop])
            ->set('spapiSellerId', 'SELLER123')
            ->call('saveAmazonSettings')
            ->assertHasErrors(['amazon_spapi']);

        $this->assertSame(0, AmazonSpapiConnection::count());
    }

    public function test_region_preset_sets_endpoint_and_marketplace_defaults_from_shop(): void
    {
        $shop = $this->amazonShop(['marketplace' => 'amazon_jp']);

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $shop])
            ->assertSet('spapiMarketplaceId', 'A1VC38T7YXB528')
            ->set('spapiSellerId', 'SELLER123')
            ->set('spapiRegion', AmazonSpapiRegion::FE)
            ->set('spapiLwaClientId', 'client-id')
            ->set('spapiLwaClientSecretInput', 'client-secret')
            ->set('spapiRefreshTokenInput', 'refresh-token')
            ->call('saveAmazonSettings')
            ->assertHasNoErrors();

        $this->assertSame('https://sellingpartnerapi-fe.amazon.com', AmazonSpapiConnection::firstOrFail()->endpoint);
    }

    public function test_test_connection_success_updates_status_and_timestamps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $connection = $this->connection();
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response([
                'access_token' => 'access-token',
                'expires_in' => 3600,
                'token_type' => 'bearer',
            ]),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $connection->shop])
            ->call('testAmazonConnection')
            ->assertHasNoErrors();

        $connection->refresh();

        $this->assertSame(AmazonSpapiConnection::STATUS_CONNECTED, $connection->status);
        $this->assertSame('2026-06-20 10:00:00', $connection->last_tested_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-20 10:00:00', $connection->last_test_successful_at->format('Y-m-d H:i:s'));
        $this->assertNull($connection->last_error);

        Http::assertSent(fn (Request $request) => $request['refresh_token'] === 'refresh-token'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret');
    }

    public function test_test_connection_failure_stores_sanitized_error(): void
    {
        $connection = $this->connection();
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'The request has an invalid grant parameter.',
            ], 400),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $connection->shop])
            ->call('testAmazonConnection');

        $connection->refresh();

        $this->assertSame(AmazonSpapiConnection::STATUS_FAILED, $connection->status);
        $this->assertStringContainsString('invalid_grant', $connection->last_error);
        $this->assertStringNotContainsString('client-secret', $connection->last_error);
        $this->assertStringNotContainsString('refresh-token', $connection->last_error);
    }

    public function test_sync_disabled_does_not_delete_credentials_or_overwrite_status(): void
    {
        $connection = $this->connection([
            'status' => AmazonSpapiConnection::STATUS_CONNECTED,
            'sync_enabled' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $connection->shop])
            ->set('spapiSyncEnabled', false)
            ->call('saveAmazonSettings')
            ->assertHasNoErrors();

        $connection->refresh();

        $this->assertFalse($connection->sync_enabled);
        $this->assertSame(AmazonSpapiConnection::STATUS_CONNECTED, $connection->status);
        $this->assertSame('client-secret', $connection->lwa_client_secret);
        $this->assertSame('refresh-token', $connection->refresh_token);
    }

    public function test_no_aws_iam_or_sigv4_fields_are_present(): void
    {
        $contents = strtolower(
            file_get_contents(database_path('migrations/2026_06_20_000006_create_amazon_spapi_connections_table.php')).
            file_get_contents(app_path('Models/AmazonSpapiConnection.php')).
            file_get_contents(app_path('Livewire/ShopEdit.php')).
            file_get_contents(resource_path('views/livewire/shop-edit.blade.php'))
        );

        foreach (['aws_access_key', 'aws_secret_key', 'aws_region', 'role_arn'] as $field) {
            $this->assertStringNotContainsString($field, $contents);
        }
    }

    public function test_activity_log_does_not_contain_plaintext_secrets(): void
    {
        $this->connection();

        $properties = DB::table('activity_log')->pluck('properties')->implode("\n");

        $this->assertStringNotContainsString('client-secret', $properties);
        $this->assertStringNotContainsString('refresh-token', $properties);
    }

    public function test_connection_test_is_blocked_until_saved(): void
    {
        $shop = $this->amazonShop();
        Http::fake();

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $shop])
            ->call('testAmazonConnection')
            ->assertHasErrors(['amazon_test']);

        Http::assertNothingSent();
    }

    public function test_connection_test_blocks_unsaved_form_input(): void
    {
        $connection = $this->connection();
        Http::fake();

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $connection->shop])
            ->set('spapiRefreshTokenInput', 'unsaved-refresh-token')
            ->call('testAmazonConnection')
            ->assertHasErrors(['amazon_test']);

        Http::assertNothingSent();
        $this->assertSame(AmazonSpapiConnection::STATUS_NOT_TESTED, $connection->refresh()->status);
    }

    public function test_marketplace_region_mismatch_is_rejected(): void
    {
        $shop = $this->amazonShop(['marketplace' => 'JP']);

        Livewire::actingAs($this->internalUser())
            ->test(ShopEdit::class, ['shop' => $shop])
            ->set('spapiSellerId', 'SELLER123')
            ->set('spapiMarketplaceId', 'A1VC38T7YXB528')
            ->set('spapiRegion', AmazonSpapiRegion::NA)
            ->set('spapiLwaClientId', 'client-id')
            ->set('spapiLwaClientSecretInput', 'client-secret')
            ->set('spapiRefreshTokenInput', 'refresh-token')
            ->call('saveAmazonSettings')
            ->assertHasErrors(['marketplace_id']);
    }

    private function connection(array $attributes = []): AmazonSpapiConnection
    {
        $shop = $this->amazonShop();

        return AmazonSpapiConnection::query()->create(array_merge([
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'seller_id' => 'SELLER123',
            'marketplace_id' => 'A1VC38T7YXB528',
            'region' => AmazonSpapiRegion::FE,
            'endpoint' => AmazonSpapiRegion::endpoint(AmazonSpapiRegion::FE),
            'lwa_client_id' => 'client-id',
            'lwa_client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'sync_enabled' => true,
            'status' => AmazonSpapiConnection::STATUS_NOT_TESTED,
        ], $attributes));
    }

    private function amazonShop(array $attributes = []): Shop
    {
        return Shop::factory()->create(array_merge([
            'platform' => 'amazon',
            'marketplace' => 'JP',
        ], $attributes));
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
