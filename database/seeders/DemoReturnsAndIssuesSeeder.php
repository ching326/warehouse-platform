<?php

namespace Database\Seeders;

use App\Models\Issue;
use App\Models\IssueLine;
use App\Models\ReturnOrder;
use App\Models\ReturnOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoReturnsAndIssuesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = $this->tenant();
        $warehouse = $this->warehouse();
        $user = $this->user();
        $skus = $this->skus($tenant);

        if ($skus === []) {
            return;
        }

        $this->seedIssues($tenant, $user, $skus);
        $this->seedReturnOrders($tenant, $warehouse, $user, $skus);
    }

    /**
     * @param  list<Sku>  $skus
     */
    private function seedIssues(Tenant $tenant, User $user, array $skus): void
    {
        $configs = [
            [
                'marker' => 'demo-issue-missing-unit',
                'type' => Issue::TYPE_MISSING,
                'status' => Issue::STATUS_OPEN,
                'reported_by' => 'Amazon JP customer support',
                'note' => 'Customer reported one unit missing from delivered package.',
                'condition' => IssueLine::CONDITION_MISSING,
                'action' => IssueLine::ACTION_RESEND,
                'qty' => 1,
            ],
            [
                'marker' => 'demo-issue-damaged-corner',
                'type' => Issue::TYPE_DAMAGED,
                'status' => Issue::STATUS_INVESTIGATING,
                'reported_by' => 'Warehouse QC',
                'note' => 'Outer carton was crushed and product corner is damaged.',
                'condition' => IssueLine::CONDITION_DAMAGED,
                'action' => IssueLine::ACTION_INVESTIGATE,
                'qty' => 2,
            ],
            [
                'marker' => 'demo-issue-waiting-return',
                'type' => Issue::TYPE_RETURNED,
                'status' => Issue::STATUS_WAITING_RETURN,
                'reported_by' => 'Shopify support',
                'note' => 'Customer return approved, waiting for returned parcel.',
                'condition' => IssueLine::CONDITION_UNKNOWN,
                'action' => IssueLine::ACTION_RETURN_TO_STOCK,
                'qty' => 1,
            ],
            [
                'marker' => 'demo-issue-wrong-item',
                'type' => Issue::TYPE_WRONG_ITEM,
                'status' => Issue::STATUS_RECEIVED_RETURN,
                'reported_by' => 'Inbound receiving',
                'note' => 'Returned item received, suspected wrong SKU shipped.',
                'condition' => IssueLine::CONDITION_GOOD,
                'action' => IssueLine::ACTION_INVESTIGATE,
                'qty' => 1,
            ],
        ];

        foreach ($configs as $index => $config) {
            $sku = $skus[$index % count($skus)];
            $issue = Issue::query()
                ->where('note', 'like', '[demo:'.$config['marker'].']%')
                ->first();

            if (! $issue) {
                $issue = Issue::create([
                    'tenant_id' => $tenant->id,
                    'issue_no' => 'ISS-PENDING-'.Str::uuid(),
                    'issue_type' => $config['type'],
                    'status' => $config['status'],
                ]);
            }

            $issue->update([
                'tenant_id' => $tenant->id,
                'issue_no' => str_starts_with($issue->issue_no, 'ISS-PENDING-')
                    ? Issue::buildIssueNo($issue->id)
                    : $issue->issue_no,
                'issue_type' => $config['type'],
                'status' => $config['status'],
                'reported_at' => now()->subDays(4 - min($index, 3)),
                'reported_by' => $config['reported_by'],
                'note' => '[demo:'.$config['marker'].'] '.$config['note'],
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
                'resolved_at' => null,
            ]);

            $issue->lines()->delete();
            IssueLine::create([
                'issue_id' => $issue->id,
                'tenant_id' => $tenant->id,
                'sku_id' => $sku->id,
                'stock_item_id' => $sku->stock_item_id,
                'qty' => $config['qty'],
                'condition' => $config['condition'],
                'action' => $config['action'],
                'note' => 'Demo issue line for '.$sku->sku.'.',
            ]);
        }
    }

    /**
     * @param  list<Sku>  $skus
     */
    private function seedReturnOrders(Tenant $tenant, Warehouse $warehouse, User $user, array $skus): void
    {
        $configs = [
            [
                'external_id' => 'DEMO-RTN-001',
                'status' => ReturnOrder::STATUS_ANNOUNCED,
                'type' => ReturnOrder::TYPE_CUSTOMER_RETURN,
                'reason' => ReturnOrder::REASON_CUSTOMER_CHANGED_MIND,
                'customer' => 'Aiko Tanaka',
                'tracking' => 'DEMOJP000001',
                'expected_qty' => 1,
                'received_qty' => 0,
                'condition' => ReturnOrderLine::CONDITION_UNKNOWN,
                'disposition' => ReturnOrderLine::DISPOSITION_UNDECIDED,
            ],
            [
                'external_id' => 'DEMO-RTN-002',
                'status' => ReturnOrder::STATUS_IN_TRANSIT,
                'type' => ReturnOrder::TYPE_MARKETPLACE_RETURN,
                'reason' => ReturnOrder::REASON_DAMAGED_IN_TRANSIT,
                'customer' => 'Kenji Nakamura',
                'tracking' => 'DEMOJP000002',
                'expected_qty' => 2,
                'received_qty' => 0,
                'condition' => ReturnOrderLine::CONDITION_UNKNOWN,
                'disposition' => ReturnOrderLine::DISPOSITION_UNDECIDED,
            ],
            [
                'external_id' => 'DEMO-RTN-003',
                'status' => ReturnOrder::STATUS_RECEIVED,
                'type' => ReturnOrder::TYPE_REFUSED_DELIVERY,
                'reason' => ReturnOrder::REASON_REFUSED_UNDELIVERED,
                'customer' => 'Sakura Kobayashi',
                'tracking' => 'DEMOJP000003',
                'expected_qty' => 1,
                'received_qty' => 1,
                'condition' => ReturnOrderLine::CONDITION_RESELLABLE,
                'disposition' => ReturnOrderLine::DISPOSITION_UNDECIDED,
            ],
            [
                'external_id' => 'DEMO-RTN-004',
                'status' => ReturnOrder::STATUS_AWAITING_DISPOSITION,
                'type' => ReturnOrder::TYPE_FBA_REMOVAL,
                'reason' => ReturnOrder::REASON_FBA_REMOVAL,
                'customer' => 'FBA Removal Batch',
                'tracking' => 'DEMOJP000004',
                'expected_qty' => 3,
                'received_qty' => 3,
                'condition' => ReturnOrderLine::CONDITION_DAMAGED,
                'disposition' => ReturnOrderLine::DISPOSITION_MARK_DAMAGED,
            ],
        ];

        foreach ($configs as $index => $config) {
            $sku = $skus[$index % count($skus)];
            $returnOrder = ReturnOrder::query()
                ->where('external_return_id', $config['external_id'])
                ->first();

            if (! $returnOrder) {
                $returnOrder = ReturnOrder::create([
                    'tenant_id' => $tenant->id,
                    'warehouse_id' => $warehouse->id,
                    'return_no' => 'RTN-PENDING-'.Str::uuid(),
                    'external_return_id' => $config['external_id'],
                ]);
            }

            $receivedAt = $config['received_qty'] > 0 ? now()->subDays(1) : null;

            $returnOrder->update([
                'tenant_id' => $tenant->id,
                'warehouse_id' => $warehouse->id,
                'return_no' => str_starts_with($returnOrder->return_no, 'RTN-PENDING-')
                    ? ReturnOrder::buildReturnNo($returnOrder->id, $tenant->code)
                    : $returnOrder->return_no,
                'status' => $config['status'],
                'return_type' => $config['type'],
                'return_reason' => $config['reason'],
                'reason_note' => 'Demo return order for '.$config['customer'].'.',
                'external_return_id' => $config['external_id'],
                'original_order_no' => 'DEMO-ORDER-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'customer_name' => $config['customer'],
                'sender_name' => $config['customer'],
                'sender_phone' => '+81-3-5555-'.str_pad((string) ($index + 100), 4, '0', STR_PAD_LEFT),
                'shipping_method' => 'Yamato',
                'tracking_no' => $config['tracking'],
                'payment_type' => ReturnOrder::PAYMENT_PREPAID,
                'package_count' => 1,
                'expected_arrival_date' => now()->addDays($index + 1)->toDateString(),
                'received_at' => $receivedAt,
                'arrived_at' => $receivedAt,
                'note' => '[demo:'.$config['external_id'].'] Seeded return order for list testing.',
                'created_by_user_id' => $user->id,
                'received_by_user_id' => $receivedAt ? $user->id : null,
            ]);

            $returnOrder->lines()->delete();
            ReturnOrderLine::create([
                'return_order_id' => $returnOrder->id,
                'tenant_id' => $tenant->id,
                'sku_id' => $sku->id,
                'stock_item_id' => $sku->stock_item_id,
                'expected_qty' => $config['expected_qty'],
                'received_qty' => $config['received_qty'],
                'condition' => $config['condition'],
                'disposition' => $config['disposition'],
                'received_at' => $receivedAt,
                'note' => 'Demo return line for '.$sku->sku.'.',
            ]);
        }
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->where('status', 'active')->first()
            ?? Tenant::create([
                'code' => 'DEMO',
                'name' => 'Demo Tenant',
                'contact_name' => 'Demo Ops',
                'contact_email' => 'demo@example.test',
                'status' => 'active',
            ]);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->where('status', 'active')->first()
            ?? Warehouse::create([
                'code' => 'DEMO-WH',
                'name' => 'Demo Warehouse',
                'country_code' => 'JP',
                'timezone' => 'Asia/Tokyo',
                'status' => 'active',
            ]);
    }

    private function user(): User
    {
        return User::query()->where('user_type', 'internal')->first()
            ?? User::factory()->create([
                'name' => 'Demo Admin',
                'email' => 'demo-admin@example.test',
                'user_type' => 'internal',
                'is_active' => true,
            ]);
    }

    /**
     * @return list<Sku>
     */
    private function skus(Tenant $tenant): array
    {
        $skus = Sku::query()
            ->where('tenant_id', $tenant->id)
            ->where('sku_type', 'single')
            ->whereNotNull('stock_item_id')
            ->orderBy('id')
            ->limit(4)
            ->get();

        if ($skus->isNotEmpty()) {
            return $skus->all();
        }

        $shop = Shop::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->first()
            ?? Shop::create([
                'tenant_id' => $tenant->id,
                'platform' => 'shopify',
                'marketplace' => 'JP',
                'code' => 'DEMO-SHOP',
                'name' => 'Demo Shop',
                'status' => 'active',
            ]);

        $created = [];

        for ($i = 1; $i <= 4; $i++) {
            $stockItem = StockItem::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'DEMO-RET-'.$i],
                [
                    'name' => 'Demo Return Item '.$i,
                    'short_name' => 'Demo Item '.$i,
                    'barcode_type' => 'unknown',
                    'product_type' => 'normal',
                    'is_dangerous_goods' => false,
                    'requires_expiry_tracking' => false,
                    'requires_lot_tracking' => false,
                    'status' => 'active',
                ],
            );

            $created[] = Sku::updateOrCreate(
                ['tenant_id' => $tenant->id, 'shop_id' => $shop->id, 'sku' => 'DEMO-RET-SKU-'.$i],
                [
                    'stock_item_id' => $stockItem->id,
                    'name' => 'Demo Return SKU '.$i,
                    'sku_type' => 'single',
                    'status' => 'active',
                ],
            );
        }

        return $created;
    }
}
