<?php

namespace Tests\Feature;

use App\Livewire\IssueShow;
use App\Livewire\ReturnOrderDisposition;
use App\Livewire\ReturnOrderIndex;
use App\Livewire\ReturnOrderReceive;
use App\Livewire\ReturnOrderShow;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Issue;
use App\Models\MediaAsset;
use App\Models\ReturnOrder;
use App\Models\ReturnOrderCost;
use App\Models\ReturnOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_open_return_orders_index(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('return-orders.index'))
            ->assertOk()
            ->assertSee(__('return_orders.page_title'));
    }

    public function test_build_return_no_uses_tenant_code_date_and_sequence(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'acme']);

        $no = ReturnOrder::buildReturnNo(7, $tenant->code, CarbonImmutable::create(2026, 6, 23, 0, 0, 0, 'Asia/Tokyo'));

        $this->assertSame('RTN-ACME-260623-007', $no);
    }

    public function test_return_order_index_hides_terminal_status_orders_by_default(): void
    {
        $tenant = Tenant::factory()->create();
        [$active] = $this->returnOrderWithLine($tenant);
        [$closed] = $this->returnOrderWithLine($tenant);
        $closed->update(['status' => ReturnOrder::STATUS_CLOSED]);

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderIndex::class)
            ->assertSee($active->return_no)
            ->assertDontSee($closed->return_no)
            ->set('statusFilter', ReturnOrder::STATUS_CLOSED)
            ->assertSee($closed->return_no)
            ->assertDontSee($active->return_no);
    }

    public function test_return_order_index_hides_quantity_summary_and_rounds_costs(): void
    {
        [$order] = $this->returnOrderWithLine(expectedQty: 2, receivedQty: 1);
        $order->costs()->create([
            'tenant_id' => $order->tenant_id,
            'cost_type' => ReturnOrderCost::COST_OTHER,
            'amount' => '123.45',
            'currency' => 'JPY',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderIndex::class)
            ->assertSee($order->return_no)
            ->assertDontSee(__('return_orders.col_summary'))
            ->assertDontSee('2 / 1')
            ->assertSee('JPY 123')
            ->assertDontSee('JPY 123.45');
    }

    public function test_receive_return_does_not_create_inventory_movement(): void
    {
        [$order, $line, , , $location] = $this->returnOrderWithLine(expectedQty: 2);

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderReceive::class, ['returnOrder' => $order])
            ->set("lineDrafts.{$line->id}.received_qty", '2')
            ->set("lineDrafts.{$line->id}.received_location_id", (string) $location->id)
            ->call('saveReceive')
            ->assertRedirect(route('return-orders.show', $order));

        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(2, $line->refresh()->received_qty);
        $this->assertSame(ReturnOrder::STATUS_RECEIVED, $order->refresh()->status);
    }

    public function test_receive_rejects_negative_received_quantity(): void
    {
        [$order, $line] = $this->returnOrderWithLine(expectedQty: 2);

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderReceive::class, ['returnOrder' => $order])
            ->set("lineDrafts.{$line->id}.received_qty", '-1')
            ->call('saveReceive')
            ->assertHasErrors(["lineDrafts.{$line->id}.received_qty"]);

        $this->assertSame(0, $line->refresh()->received_qty);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_disposition_return_to_inventory_creates_receive_movement_with_return_context(): void
    {
        [$order, $line, $tenant, $warehouse, $location] = $this->returnOrderWithLine(expectedQty: 2, receivedQty: 2);

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderDisposition::class, ['returnOrder' => $order])
            ->set("lineDrafts.{$line->id}.disposition", ReturnOrderLine::DISPOSITION_RETURN_TO_INVENTORY)
            ->set("lineDrafts.{$line->id}.disposition_location_id", (string) $location->id)
            ->call('confirmDisposition')
            ->assertRedirect(route('return-orders.show', $order));

        $movement = InventoryMovement::firstOrFail();
        $this->assertSame(InventoryMovement::TYPE_RECEIVE, $movement->movement_type);
        $this->assertSame(2, $movement->quantity_delta);
        $this->assertSame('return_order', $movement->ref_type);
        $this->assertSame((string) $order->id, $movement->ref_id);
        $this->assertNotNull($movement->user_id);

        $balance = InventoryBalance::query()
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $line->stock_item_id)
            ->firstOrFail();

        $this->assertSame(2, $balance->on_hand_qty);
        $this->assertSame(2, $balance->available_qty);
        $this->assertSame(ReturnOrder::STATUS_DISPOSITIONED, $order->refresh()->status);
    }

    public function test_disposition_is_idempotent_after_return_is_dispositioned(): void
    {
        [$order, $line, , , $location] = $this->returnOrderWithLine(expectedQty: 2, receivedQty: 2);

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderDisposition::class, ['returnOrder' => $order])
            ->set("lineDrafts.{$line->id}.disposition", ReturnOrderLine::DISPOSITION_RETURN_TO_INVENTORY)
            ->set("lineDrafts.{$line->id}.disposition_location_id", (string) $location->id)
            ->call('confirmDisposition')
            ->assertRedirect(route('return-orders.show', $order));

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderDisposition::class, ['returnOrder' => $order->refresh()])
            ->call('confirmDisposition')
            ->assertRedirect(route('return-orders.show', $order));

        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame(2, InventoryBalance::firstOrFail()->on_hand_qty);
        $this->assertSame(2, InventoryBalance::firstOrFail()->available_qty);
    }

    public function test_disposition_rejects_unknown_disposition_value(): void
    {
        [$order, $line] = $this->returnOrderWithLine(expectedQty: 1, receivedQty: 1);

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderDisposition::class, ['returnOrder' => $order])
            ->set("lineDrafts.{$line->id}.disposition", 'not_a_real_disposition')
            ->call('confirmDisposition')
            ->assertHasErrors(["lineDrafts.{$line->id}.disposition"]);

        $this->assertSame(ReturnOrderLine::DISPOSITION_UNDECIDED, $line->refresh()->disposition);
        $this->assertNull($line->dispositioned_at);
        $this->assertSame(ReturnOrder::STATUS_RECEIVED, $order->refresh()->status);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_damaged_disposition_receives_then_marks_damaged(): void
    {
        [$order, $line] = $this->returnOrderWithLine(expectedQty: 1, receivedQty: 1);

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderDisposition::class, ['returnOrder' => $order])
            ->set("lineDrafts.{$line->id}.disposition", ReturnOrderLine::DISPOSITION_MARK_DAMAGED)
            ->call('confirmDisposition');

        $this->assertSame([
            InventoryMovement::TYPE_RECEIVE,
            InventoryMovement::TYPE_MARK_DAMAGED,
        ], InventoryMovement::query()->orderBy('id')->pluck('movement_type')->all());

        $balance = InventoryBalance::firstOrFail();
        $this->assertSame(1, $balance->on_hand_qty);
        $this->assertSame(1, $balance->damaged_qty);
        $this->assertSame(0, $balance->available_qty);
    }

    public function test_issue_detail_shows_linked_return_orders(): void
    {
        [$order] = $this->returnOrderWithLine();
        $issue = Issue::create([
            'tenant_id' => $order->tenant_id,
            'issue_no' => 'ISS-PENDING-test',
            'issue_type' => Issue::TYPE_RETURNED,
            'status' => Issue::STATUS_WAITING_RETURN,
        ]);
        $issue->update(['issue_no' => Issue::buildIssueNo($issue->id)]);
        $order->update(['issue_id' => $issue->id]);

        Livewire::actingAs($this->internalUser())
            ->test(IssueShow::class, ['issue' => $issue])
            ->assertSee($order->return_no);
    }

    public function test_return_order_photo_upload_uses_private_disk_and_correct_model_type(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$tenant, $user] = $this->tenantUser();
        [$order] = $this->returnOrderWithLine($tenant);

        Livewire::actingAs($user)
            ->test(ReturnOrderShow::class, ['returnOrder' => $order])
            ->set('photo', UploadedFile::fake()->image('return-damage.webp', 90, 70))
            ->set('photoType', 'damage')
            ->call('uploadPhoto')
            ->assertHasNoErrors();

        $asset = MediaAsset::firstOrFail();

        $this->assertSame('local', $asset->disk);
        $this->assertSame($tenant->id, $asset->tenant_id);
        $this->assertSame(MediaAsset::MODEL_TYPE_RETURN_ORDER, $asset->model_type);
        $this->assertSame($order->id, $asset->model_id);
        $this->assertSame(90, $asset->width);
        $this->assertSame(70, $asset->height);
        Storage::disk('local')->assertExists($asset->path);
        Storage::disk('public')->assertMissing($asset->path);
    }

    public function test_tenant_user_cannot_upload_return_order_photo_for_another_tenant(): void
    {
        Storage::fake('local');
        [, $user] = $this->tenantUser();
        [$order] = $this->returnOrderWithLine();

        Livewire::actingAs($user)
            ->test(ReturnOrderShow::class, ['returnOrder' => $order])
            ->assertForbidden();

        $this->assertSame(0, MediaAsset::count());
    }

    public function test_return_order_photo_delete_removes_row_and_private_file(): void
    {
        Storage::fake('local');
        [$order] = $this->returnOrderWithLine();
        Storage::disk('local')->put('media/private/tenant-'.$order->tenant_id.'/return-orders/'.$order->id.'/delete.jpg', 'image');
        $asset = $this->mediaAsset($order, [
            'path' => 'media/private/tenant-'.$order->tenant_id.'/return-orders/'.$order->id.'/delete.jpg',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderShow::class, ['returnOrder' => $order])
            ->call('deletePhoto', $asset->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('local')->assertMissing($asset->path);
    }

    public function test_return_order_photo_soft_limit_rejects_eleventh_image(): void
    {
        Storage::fake('local');
        [$order] = $this->returnOrderWithLine();

        foreach (range(1, 10) as $index) {
            $this->mediaAsset($order, ['file_name' => 'existing-'.$index.'.jpg']);
        }

        Livewire::actingAs($this->internalUser())
            ->test(ReturnOrderShow::class, ['returnOrder' => $order])
            ->set('photo', UploadedFile::fake()->image('eleventh.jpg'))
            ->call('uploadPhoto')
            ->assertHasErrors(['photo']);

        $this->assertSame(10, MediaAsset::count());
    }

    public function test_media_asset_url_returns_public_url_for_public_disk_and_media_route_for_private_disk(): void
    {
        [$order] = $this->returnOrderWithLine();
        $private = $this->mediaAsset($order);
        $public = MediaAsset::create([
            'tenant_id' => $order->tenant_id,
            'model_type' => MediaAsset::MODEL_TYPE_STOCK_ITEM,
            'model_id' => 123,
            'type' => 'main',
            'disk' => 'public',
            'path' => 'product-images/tenant-'.$order->tenant_id.'/stock-items/123/public.jpg',
            'file_name' => 'public.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'is_primary' => true,
        ]);

        $this->assertSame('/storage/product-images/tenant-'.$order->tenant_id.'/stock-items/123/public.jpg', parse_url($public->url(), PHP_URL_PATH));
        $this->assertSame(route('media.show', $private), $private->url());
    }

    private function returnOrderWithLine(?Tenant $tenant = null, int $expectedQty = 1, int $receivedQty = 0): array
    {
        $tenant ??= Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $location = WarehouseLocation::factory()->for($warehouse)->create();
        $shop = Shop::factory()->for($tenant)->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create();

        $order = ReturnOrder::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'return_no' => 'RTN-PENDING-test',
            'status' => ReturnOrder::STATUS_RECEIVED,
            'return_type' => ReturnOrder::TYPE_CUSTOMER_RETURN,
            'return_reason' => ReturnOrder::REASON_OTHER,
            'payment_type' => ReturnOrder::PAYMENT_UNKNOWN,
        ]);
        $order->update(['return_no' => ReturnOrder::buildReturnNo($order->id, $tenant->code)]);

        $line = $order->lines()->create([
            'tenant_id' => $tenant->id,
            'sku_id' => $sku->id,
            'stock_item_id' => $stockItem->id,
            'expected_qty' => $expectedQty,
            'received_qty' => $receivedQty,
            'condition' => ReturnOrderLine::CONDITION_RESELLABLE,
            'disposition' => ReturnOrderLine::DISPOSITION_UNDECIDED,
        ]);

        return [$order, $line, $tenant, $warehouse, $location];
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantUser(): array
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

        return [$tenant, $user];
    }

    private function mediaAsset(ReturnOrder $order, array $attributes = []): MediaAsset
    {
        return MediaAsset::create(array_merge([
            'tenant_id' => $order->tenant_id,
            'model_type' => MediaAsset::MODEL_TYPE_RETURN_ORDER,
            'model_id' => $order->id,
            'type' => 'damage',
            'disk' => 'local',
            'path' => 'media/private/tenant-'.$order->tenant_id.'/return-orders/'.$order->id.'/test.jpg',
            'file_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'is_primary' => false,
        ], $attributes));
    }
}
