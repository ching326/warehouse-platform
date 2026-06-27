<?php

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\InboundOrder;
use App\Models\InboundOrderLine;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OutboundOrder;
use App\Models\OutboundOrderLine;
use App\Models\PackagingMaterial;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodRate;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\SkuBundleComponent;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class WarehousePlatformSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenants = $this->seedTenants();
        $users = $this->seedUsers($tenants);
        $this->seedTenantUsers($tenants, $users);
        $warehouses = $this->seedWarehouses();
        $this->seedWarehouseLocations($warehouses);
        $packagingMaterials = $this->seedPackagingMaterials();
        $shippingMethods = $this->seedShippingMethods();
        $shops = $this->seedShops($tenants);
        $stockItems = $this->seedStockItems($tenants);
        $skus = $this->seedSkus($tenants, $shops, $stockItems, $packagingMaterials);
        [$reportSkus, $reportStockItems] = $this->seedAmazonOrderReportSkus($tenants, $shops, $packagingMaterials);
        $skus = array_merge($skus, $reportSkus);
        $stockItems = array_merge($stockItems, $reportStockItems);
        $this->seedSkuBundles($tenants, $skus, $stockItems);
        $this->seedInventory(app(InventoryService::class), $tenants, $warehouses, $stockItems);
        $this->seedSalesOrders($tenants, $shops, $skus, $users, $shippingMethods);
        $this->seedInboundOrders($tenants, $warehouses, $skus, $users);
        $this->seedOutboundOrders($tenants, $warehouses, $skus, $users, $shippingMethods);
    }

    private function seedShippingMethods(): array
    {
        $carrierConfigs = [
            ['yamato', 'Yamato', 'JP'],
            ['sagawa', 'Sagawa', 'JP'],
            ['japan_post', 'Japan Post', 'JP'],
            ['other', 'Other', null],
        ];

        $carriers = [];
        foreach ($carrierConfigs as [$code, $name, $country]) {
            $carriers[$code] = Carrier::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'country_code' => $country, 'status' => 'active'],
            );
        }

        $configs = [
            ['yamato_nekopos', 'Yamato Nekopos', 'yamato', 'mail', false, false, true],
            ['yamato_tqb', 'Yamato TQB', 'yamato', 'parcel', true, true, true],
            ['yamato_compact', 'Yamato Compact', 'yamato', 'compact', false, true, true],
            ['sagawa_thb', 'Sagawa THB', 'sagawa', 'parcel', true, true, true],
            ['japan_post_yupack', 'Japan Post Yu-Pack', 'japan_post', 'parcel', true, true, true],
            ['other', 'Other', 'other', 'other', false, false, false],
        ];

        $methods = [];
        foreach ($configs as [$code, $name, $carrierCode, $type, $requiresSize, $requiresZone, $supportsCsv]) {
            $methods[$code] = ShippingMethod::updateOrCreate(
                ['code' => $code],
                [
                    'carrier_id' => $carriers[$carrierCode]->id,
                    'name' => $name,
                    'service_type' => $type,
                    'is_trackable' => true,
                    'requires_size' => $requiresSize,
                    'requires_zone' => $requiresZone,
                    'supports_courier_csv' => $supportsCsv,
                    'status' => 'active',
                ],
            );
        }

        ShippingMethodRate::updateOrCreate(
            ['shipping_method_id' => $methods['yamato_nekopos']->id, 'tenant_id' => null, 'rate_type' => 'flat', 'currency' => 'JPY'],
            ['price' => 198, 'status' => 'active'],
        );

        return $methods;
    }

    private function seedTenants(): array
    {
        $tenants = [];
        $configs = [
            ['ABC', 'ABC Trading Co., Ltd.', 'Aiko Tanaka', 'ops@abc.test', '+81-3-1234-5678'],
            ['XYZ', 'XYZ Retail Limited', 'Bob Chen', 'ops@xyz.test', '+852-2345-6789'],
            ['DEF', 'DEF Electronics GmbH', 'Clara Schmidt', 'ops@def.test', '+49-30-1234567'],
        ];

        foreach ($configs as $cfg) {
            $tenants[] = Tenant::updateOrCreate(
                ['code' => $cfg[0]],
                [
                    'name' => $cfg[1],
                    'contact_name' => $cfg[2],
                    'contact_email' => $cfg[3],
                    'contact_phone' => $cfg[4],
                    'billing_terms' => 'net_30',
                    'status' => 'active',
                    'notes' => "Demo tenant for {$cfg[1]} workflows.",
                ],
            );
        }

        return $tenants;
    }

    private function seedUsers(array $tenants): array
    {
        $users = [];

        User::updateOrCreate(['email' => 'admin@warehouse.test'], [
            'name' => 'Warehouse Admin',
            'password' => 'password',
            'user_type' => 'internal',
            'is_active' => true,
        ]);

        User::updateOrCreate(['email' => 'ops@warehouse.test'], [
            'name' => 'Operations Lead',
            'password' => 'password',
            'user_type' => 'internal',
            'is_active' => true,
        ]);

        foreach ($tenants as $i => $tenant) {
            $email = 'owner-'.($i + 1).'@tenant.test';
            $users[$tenant->id] = User::updateOrCreate(['email' => $email], [
                'name' => $tenant->contact_name,
                'password' => 'password',
                'user_type' => 'tenant',
                'is_active' => true,
            ]);
        }

        return $users;
    }

    private function seedTenantUsers(array $tenants, array $users): void
    {
        foreach ($tenants as $tenant) {
            if (isset($users[$tenant->id])) {
                TenantUser::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $users[$tenant->id]->id],
                    ['role' => 'owner', 'status' => 'active', 'joined_at' => now()->subDays(30)],
                );
            }
        }
    }

    private function seedWarehouses(): array
    {
        return [
            Warehouse::updateOrCreate(['code' => 'JP-TOKYO-01'], [
                'name' => 'Tokyo Warehouse',
                'country_code' => 'JP',
                'timezone' => 'Asia/Tokyo',
                'postal_code' => '143-0001',
                'state' => 'Tokyo',
                'city' => 'Ota',
                'address_line1' => '1-1-1 Heiwajima',
                'phone' => '+81-3-5555-0101',
                'status' => 'active',
            ]),
            Warehouse::updateOrCreate(['code' => 'CN-SZ-01'], [
                'name' => 'Shenzhen Warehouse',
                'country_code' => 'CN',
                'timezone' => 'Asia/Shanghai',
                'postal_code' => '518000',
                'state' => 'Guangdong',
                'city' => 'Shenzhen',
                'address_line1' => '88 Logistics Road',
                'phone' => '+86-755-5555-0101',
                'status' => 'active',
            ]),
            Warehouse::updateOrCreate(['code' => 'SG-JURONG-01'], [
                'name' => 'Singapore Warehouse',
                'country_code' => 'SG',
                'timezone' => 'Asia/Singapore',
                'postal_code' => '600001',
                'state' => 'Singapore',
                'city' => 'Jurong East',
                'address_line1' => '123 Tech Park',
                'phone' => '+65-6555-0101',
                'status' => 'active',
            ]),
        ];
    }

    private function seedWarehouseLocations(array $warehouses): void
    {
        $storageUnitTypes = ['bin', 'rack', 'shelf', 'cage'];
        $locations = ['A', 'B', 'C', 'D', 'E'];

        foreach ($warehouses as $warehouse) {
            foreach ($locations as $loc) {
                foreach ($storageUnitTypes as $type) {
                    WarehouseLocation::updateOrCreate(
                        ['warehouse_id' => $warehouse->id, 'code' => "{$loc}-{$type}"],
                        [
                            'zone_type' => 'storage',
                            'storage_unit_type' => $type,
                            'status' => 'active',
                        ],
                    );
                }
            }
        }
    }

    private function seedPackagingMaterials(): array
    {
        return [
            PackagingMaterial::updateOrCreate(['code' => 'BOX-S'], [
                'name' => 'Small Carton Box',
                'type' => 'box',
                'length_value' => 20, 'width_value' => 15, 'height_value' => 10,
                'dimension_unit' => 'cm',
                'weight_value' => 100, 'weight_unit' => 'g',
                'cost' => 50, 'currency' => 'JPY',
                'status' => 'active',
                'note' => 'Small electronics.',
            ]),
            PackagingMaterial::updateOrCreate(['code' => 'BOX-M'], [
                'name' => 'Medium Carton Box',
                'type' => 'box',
                'length_value' => 35, 'width_value' => 25, 'height_value' => 15,
                'dimension_unit' => 'cm',
                'weight_value' => 180, 'weight_unit' => 'g',
                'cost' => 90, 'currency' => 'JPY',
                'status' => 'active',
                'note' => 'Medium items.',
            ]),
            PackagingMaterial::updateOrCreate(['code' => 'BOX-L'], [
                'name' => 'Large Carton Box',
                'type' => 'box',
                'length_value' => 50, 'width_value' => 40, 'height_value' => 30,
                'dimension_unit' => 'cm',
                'weight_value' => 400, 'weight_unit' => 'g',
                'cost' => 200, 'currency' => 'JPY',
                'status' => 'active',
                'note' => 'Large bulk items.',
            ]),
            PackagingMaterial::updateOrCreate(['code' => 'BUBBLE-A4'], [
                'name' => 'A4 Bubble Mailer',
                'type' => 'mailer',
                'length_value' => 33, 'width_value' => 24, 'height_value' => 2,
                'dimension_unit' => 'cm',
                'weight_value' => 35, 'weight_unit' => 'g',
                'cost' => 28, 'currency' => 'JPY',
                'status' => 'active',
                'note' => 'Lightweight mailer.',
            ]),
            PackagingMaterial::updateOrCreate(['code' => 'BUBBLE-A3'], [
                'name' => 'A3 Bubble Mailer',
                'type' => 'mailer',
                'length_value' => 48, 'width_value' => 36, 'height_value' => 3,
                'dimension_unit' => 'cm',
                'weight_value' => 55, 'weight_unit' => 'g',
                'cost' => 45, 'currency' => 'JPY',
                'status' => 'active',
                'note' => 'Standard mailer.',
            ]),
        ];
    }

    private function seedShops(array $tenants): array
    {
        $shops = [];
        $platforms = ['amazon', 'shopify', 'ebay'];
        $markets = ['JP', 'US', 'EU'];

        foreach ($tenants as $ti => $tenant) {
            foreach ($platforms as $pi => $platform) {
                foreach ($markets as $mi => $market) {
                    $shops[] = Shop::updateOrCreate(
                        ['tenant_id' => $tenant->id, 'code' => strtoupper($platform[0].substr($market, 0, 1).$ti.$pi.$mi)],
                        [
                            'platform' => $platform,
                            'marketplace' => $market,
                            'name' => "{$tenant->code} {$platform} {$market}",
                            'contact_name' => 'Shop Ops',
                            'contact_email' => "ops-{$platform}@{$tenant->code}.test",
                            'status' => 'active',
                            'note' => "{$platform} marketplace.",
                        ],
                    );
                }
            }
        }

        return $shops;
    }

    private function seedStockItems(array $tenants): array
    {
        $items = [];
        $products = [
            ['ABC-000001', 'USB-C Charger 30W', '30W Charger', 'CHG-30W', 'Black', 86],
            ['ABC-000002', 'USB-C Cable 1m', 'USB-C Cable', 'CBL-C-1M', 'White', 42],
            ['ABC-000003', 'Power Bank 20000mAh', 'Power Bank', 'PB-20000', 'Gray', 320],
            ['ABC-000004', 'Screen Protector Set', 'Screen Prot', 'SP-GLASS', 'Clear', 25],
            ['ABC-000005', 'Phone Case TPU', 'Phone Case', 'CASE-TPU', 'Black', 180],
        ];

        foreach ($tenants as $tenant) {
            foreach ($products as $idx => $prod) {
                $code = str_replace('ABC', $tenant->code, $prod[0]);
                $items[] = StockItem::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $code],
                    [
                        'name' => $prod[1],
                        'short_name' => $prod[2],
                        'brand' => $tenant->code.' Gear',
                        'model_number' => $prod[3],
                        'color' => $prod[4],
                        'barcode' => '49012345'.$tenant->id.$idx,
                        'barcode_type' => 'jan',
                        'product_type' => 'normal',
                        'is_dangerous_goods' => false,
                        'requires_expiry_tracking' => false,
                        'requires_lot_tracking' => false,
                        'weight_value' => $prod[5],
                        'weight_unit' => 'g',
                        'status' => 'active',
                    ],
                );
            }
        }

        return $items;
    }

    private function seedSkus(array $tenants, array $shops, array $stockItems, array $packagingMaterials): array
    {
        $skus = [];

        foreach ($tenants as $ti => $tenant) {
            $tenantItems = array_filter($stockItems, fn ($item) => $item->tenant_id === $tenant->id);

            foreach ($shops as $si => $shop) {
                if ($shop->tenant_id !== $tenant->id) {
                    continue;
                }

                for ($pi = 0; $pi < 3; $pi++) {
                    $itemIdx = $pi % count($tenantItems);
                    $item = array_values($tenantItems)[$itemIdx];

                    $skus[] = Sku::updateOrCreate(
                        ['tenant_id' => $tenant->id, 'shop_id' => $shop->id, 'sku' => "{$shop->code}-SKU-{$pi}"],
                        [
                            'stock_item_id' => $item->id,
                            'name' => $item->name." ({$shop->marketplace})",
                            'sku_type' => 'single',
                            'default_packaging_material_id' => $packagingMaterials[0]->id,
                            'status' => 'active',
                        ],
                    );
                }
            }
        }

        return $skus;
    }

    private function seedSkuBundles(array $tenants, array $skus, array $stockItems): void
    {
        foreach ($tenants as $tenant) {
            $tenantSkus = array_values(array_filter($skus, fn ($sku) => $sku->tenant_id === $tenant->id));
            $tenantShopId = $tenantSkus[0]->shop_id ?? null;

            if (! $tenantShopId) {
                continue;
            }

            $bundleSku = Sku::updateOrCreate(
                ['tenant_id' => $tenant->id, 'shop_id' => $tenantShopId, 'sku' => "{$tenant->code}-BUNDLE"],
                [
                    'stock_item_id' => null,
                    'name' => "{$tenant->code} Starter Bundle",
                    'sku_type' => 'virtual_bundle',
                    'status' => 'active',
                    'note' => 'Virtual bundle.',
                ],
            );

            $tenantItems = array_values(array_filter($stockItems, fn ($item) => $item->tenant_id === $tenant->id));

            foreach (array_slice($tenantItems, 0, 2) as $item) {
                SkuBundleComponent::updateOrCreate(
                    ['bundle_sku_id' => $bundleSku->id, 'component_stock_item_id' => $item->id],
                    ['tenant_id' => $tenant->id, 'quantity' => 1],
                );
            }
        }
    }

    /**
     * @return array{0: array<int, Sku>, 1: array<int, StockItem>}
     */
    private function seedAmazonOrderReportSkus(array $tenants, array $shops, array $packagingMaterials): array
    {
        $tenant = collect($tenants)->firstWhere('code', 'ABC') ?? $tenants[0] ?? null;

        if (! $tenant) {
            return [[], []];
        }

        $shop = collect($shops)->first(
            fn ($shop) => $shop->tenant_id === $tenant->id
                && $shop->platform === 'amazon'
                && $shop->marketplace === 'JP'
        );

        if (! $shop) {
            return [[], []];
        }

        $products = [
            ['ABC-000101', 'IJL-B00F4MWI2W-20200511', 'サンコム LR44アルカリボタン電池10個パック × 2シート(計20個)', 'B00F4MWI2W', 'LR44'],
            ['ABC-000102', 'IJL-B076P64BCW-20201012', 'SUNCOM CR927 3V リチウムボタン電池 1シート5個', 'B076P64BCW', 'CR927'],
            ['ABC-000103', 'IJL-B0848T94ZR-20250828', 'ムラタ（MURATA) SR920SW （371）酸化銀電池 1シート（5個入）', 'B0848T94ZR', 'SR920SW'],
            ['ABC-000104', 'IJL-B085KY7G7P-20250828', 'ムラタ（MURATA) SR516SW （317）酸化銀電池 1シート（5個入）', 'B085KY7G7P', 'SR516SW'],
            ['ABC-000105', 'IJL-B0876RMKCC-20250909', 'TIANQIU アルカリボタン電池 LR41 2個 (AG3 / LR41H / 392A) ブリスターパッケージ 切り分け 水銀フリー ゼロ 未使用 カメラ・ミニゲーム・体温計等に', 'B0876RMKCC', 'LR41'],
            ['ABC-000106', 'IJL-B088HDH5BY-20251017', 'ムラタ 373 バッテリー SR916SW 1.55V 酸化銀 時計ボタンセル(電池10個)', 'B088HDH5BY', 'SR916SW'],
            ['ABC-000107', 'IJL-B0D2XQF97Q-20250904', 'Maxell マクセル 315 SR716SW ×5個 【日本製】 酸化銀電池 ボタン 時計電池 時計用電池 時計用 SR716SW', 'B0D2XQF97Q', 'SR716SW'],
            ['ABC-000108', 'IJL-B0F83WWZ1B-20250828', '2個 murata 村田製作所 SR726W 396 日本製 ボタン電池 1.55V 時計 酸化銀電池 396', 'B0F83WWZ1B', 'SR726W'],
        ];

        $stockItems = [];
        $skus = [];
        $packagingMaterialId = $packagingMaterials[3]->id ?? $packagingMaterials[0]->id ?? null;

        foreach ($products as [$code, $skuCode, $name, $asin, $shortName]) {
            $stockItem = StockItem::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                [
                    'name' => $name,
                    'short_name' => $shortName,
                    'brand' => str_contains($name, 'ムラタ') || str_contains($name, 'murata') ? 'Murata' : 'Demo Battery',
                    'model_number' => $shortName,
                    'barcode' => null,
                    'barcode_type' => 'unknown',
                    'product_type' => 'normal',
                    'is_dangerous_goods' => false,
                    'requires_expiry_tracking' => false,
                    'requires_lot_tracking' => false,
                    'weight_value' => 10,
                    'weight_unit' => 'g',
                    'status' => 'active',
                    'note' => 'Seeded from sample Amazon order report.',
                ],
            );

            $stockItems[] = $stockItem;
            $skus[] = Sku::updateOrCreate(
                ['tenant_id' => $tenant->id, 'shop_id' => $shop->id, 'sku' => $skuCode],
                [
                    'stock_item_id' => $stockItem->id,
                    'name' => $name,
                    'platform_sku' => $skuCode,
                    'platform_product_id' => $asin,
                    'platform_variant_id' => $asin,
                    'platform_variant_name' => $shortName,
                    'platform_label_code' => null,
                    'sku_type' => 'single',
                    'default_packaging_material_id' => $packagingMaterialId,
                    'status' => 'active',
                    'note' => 'Seeded from sample Amazon order report.',
                ],
            );
        }

        return [$skus, $stockItems];
    }

    private function seedInventory(InventoryService $inventoryService, array $tenants, array $warehouses, array $stockItems): void
    {
        foreach ($warehouses as $warehouse) {
            foreach ($stockItems as $item) {
                $onHand = rand(50, 500);
                $reserved = rand(5, $onHand / 2);
                $hold = rand(0, 20);
                $damaged = rand(0, 5);
                $this->seedInventoryBalance($inventoryService, $item->tenant_id, $warehouse->id, $item->id, $onHand, $reserved, $hold, $damaged);
            }
        }
    }

    private function seedInventoryBalance(
        InventoryService $inventoryService,
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        int $onHand,
        int $reserved,
        int $hold,
        int $damaged,
    ): void {
        $referenceNumber = 'DEMO-OPENING-'.$tenantId.'-'.$warehouseId.'-'.$stockItemId;
        $demoMovementQuery = InventoryMovement::query()
            ->where('ref_type', 'demo_seed')
            ->where(function ($query) use ($referenceNumber) {
                $query
                    ->where('ref_id', $referenceNumber)
                    ->orWhere('ref_id', 'like', $referenceNumber.'-%');
            });

        $hasCurrentBucketHistory = (clone $demoMovementQuery)
            ->where('ref_id', $referenceNumber)
            ->where('on_hand_after', $onHand)
            ->where('available_after', $onHand)
            ->exists();

        if ((clone $demoMovementQuery)->exists() && ! $hasCurrentBucketHistory) {
            (clone $demoMovementQuery)->delete();
        }

        if (! (clone $demoMovementQuery)->exists()) {
            InventoryBalance::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'stock_item_id' => $stockItemId,
                ],
                [
                    'on_hand_qty' => 0,
                    'reserved_qty' => 0,
                    'available_qty' => 0,
                    'inbound_qty' => 0,
                    'hold_qty' => 0,
                    'damaged_qty' => 0,
                ],
            );

            $inventoryService->adjustStock(
                $tenantId,
                $warehouseId,
                $stockItemId,
                $onHand,
                [
                    'ref_type' => 'demo_seed',
                    'ref_id' => $referenceNumber,
                    'note' => 'Opening balance for demo warehouse data.',
                ],
            );

            if ($reserved > 0) {
                $inventoryService->reserveStock($tenantId, $warehouseId, $stockItemId, $reserved, [
                    'ref_type' => 'demo_seed',
                    'ref_id' => $referenceNumber.'-RESERVED',
                    'note' => 'Demo reserved stock.',
                ]);
            }

            if ($hold > 0) {
                $inventoryService->placeHold($tenantId, $warehouseId, $stockItemId, $hold, [
                    'ref_type' => 'demo_seed',
                    'ref_id' => $referenceNumber.'-HOLD',
                    'note' => 'Demo held stock.',
                ]);
            }

            if ($damaged > 0) {
                $inventoryService->markDamaged($tenantId, $warehouseId, $stockItemId, $damaged, [
                    'ref_type' => 'demo_seed',
                    'ref_id' => $referenceNumber.'-DAMAGED',
                    'note' => 'Demo damaged stock.',
                ]);
            }

            return;
        }

        InventoryBalance::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'stock_item_id' => $stockItemId,
            ],
            [
                'on_hand_qty' => $onHand,
                'reserved_qty' => $reserved,
                'available_qty' => $onHand - $reserved - $hold - $damaged,
                'inbound_qty' => 0,
                'hold_qty' => $hold,
                'damaged_qty' => $damaged,
            ],
        );
    }

    private function seedSalesOrders(array $tenants, array $shops, array $skus, array $users, array $shippingMethods): void
    {
        $cities = ['Tokyo', 'Osaka', 'Bangkok', 'Singapore', 'Hong Kong', 'Shanghai', 'Seoul'];
        $countryCodes = ['JP', 'TH', 'SG', 'HK', 'CN', 'KR'];
        $states = ['Tokyo', 'Kanto', 'Kansai', 'Bangkok', 'Singapore', 'Hong Kong', 'Shanghai', 'Gyeonggi'];
        $postalCodes = ['100-0001', '103-0025', '150-0002', '550-0001', '188001', '018957', '999077', '180-0002'];

        $createdByUser = $users[array_key_first($users)] ?? User::first();

        foreach ($tenants as $tenant) {
            $tenantShops = array_filter($shops, fn ($s) => $s->tenant_id === $tenant->id);
            $tenantSkus = array_filter($skus, fn ($s) => $s->tenant_id === $tenant->id);

            if (empty($tenantShops) || empty($tenantSkus)) {
                continue;
            }

            $orderCount = 0;
            $maxOrdersPerTenant = 5;

            foreach ($tenantShops as $shop) {
                $shopSkus = array_filter($tenantSkus, fn ($s) => $s->shop_id === $shop->id);
                if (empty($shopSkus)) {
                    continue;
                }

                for ($i = 0; $i < 2 && $orderCount < $maxOrdersPerTenant; $i++) {
                    $platformOrderId = strtoupper($shop->code).'-'.date('Ymd').'-'.str_pad($orderCount + 1000, 4, '0', STR_PAD_LEFT);

                    $cityIdx = ($tenant->id + $shop->id + $i) % count($cities);
                    $countryIdx = ($tenant->id + $shop->id + $i) % count($countryCodes);

                    $order = SalesOrder::updateOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'shop_id' => $shop->id,
                            'platform_order_id' => $platformOrderId,
                        ],
                        [
                            'shipping_method_id' => $shippingMethods['yamato_tqb']->id ?? null,
                            'shipping_method' => $shippingMethods['yamato_tqb']->carrier?->code ?? 'yamato',
                            'source' => SalesOrder::SOURCE_MANUAL,
                            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
                            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
                            'recipient_name' => $this->randomRecipientName($tenant->code, $i),
                            'recipient_phone' => $this->randomPhone($countryCodes[$countryIdx]),
                            'recipient_country_code' => $countryCodes[$countryIdx],
                            'recipient_postal_code' => $postalCodes[($i + $tenant->id) % count($postalCodes)],
                            'recipient_state' => $states[($i + $shop->id) % count($states)],
                            'recipient_city' => $cities[$cityIdx],
                            'recipient_address_line1' => $this->randomAddress($i),
                            'recipient_address_line2' => rand(0, 1) ? 'Apt '.(100 + $i) : '',
                            'note' => rand(0, 3) === 0 ? 'Special handling required' : '',
                            'created_by_user_id' => $createdByUser->id,
                        ],
                    );

                    $order->lines()->delete();

                    $lineCount = rand(1, 3);
                    $singleSkus = array_values(array_filter($shopSkus, fn ($s) => $s->sku_type === 'single'));
                    $selectedSkus = array_slice($singleSkus, 0, min($lineCount, count($singleSkus)));

                    foreach ($selectedSkus as $sku) {
                        SalesOrderLine::create([
                            'sales_order_id' => $order->id,
                            'sku_id' => $sku->id,
                            'quantity' => rand(1, 3),
                            'line_status' => SalesOrderLine::STATUS_READY,
                            'note' => rand(0, 2) === 0 ? 'Urgent delivery' : '',
                        ]);
                    }

                    $orderCount++;
                }

                if ($orderCount >= $maxOrdersPerTenant) {
                    break;
                }
            }
        }
    }

    private function randomRecipientName(string $tenantCode, int $seed): string
    {
        $names = [
            'ABC' => ['Aiko Tanaka', 'Hiroshi Yamamoto', 'Yuki Suzuki', 'Kenji Nakamura', 'Sakura Kobayashi'],
            'XYZ' => ['Chan Tai Man', 'Lee Siu Ming', 'Wong Hoi Ying', 'Chen Wei', 'Lau Kwan'],
            'DEF' => ['Hans Mueller', 'Maria Schmidt', 'Klaus Weber', 'Petra Fischer', 'Thomas Meyer'],
        ];

        $list = $names[$tenantCode] ?? $names['ABC'];

        return $list[$seed % count($list)];
    }

    private function randomPhone(string $countryCode): string
    {
        return match ($countryCode) {
            'JP' => '+81-'.(rand(3, 9)).'-'.rand(1000, 9999).'-'.rand(1000, 9999),
            'TH' => '+66-'.rand(2, 9).'-'.rand(1000, 9999).'-'.rand(100, 999),
            'SG' => '+65-'.rand(6000, 9999).' '.rand(1000, 9999),
            'HK' => '+852-'.rand(2000, 9999).' '.rand(1000, 9999),
            'CN' => '+86-'.rand(10, 99).'-'.rand(1000, 9999).'-'.rand(1000, 9999),
            'KR' => '+82-'.rand(2, 9).'-'.rand(100, 9999).'-'.rand(1000, 9999),
            default => '+81-3-5555-'.str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
        };
    }

    private function randomAddress(int $seed): string
    {
        $addresses = [
            '123 Tech Park, Building A',
            '456 Innovation Road, Suite 200',
            '789 Commerce Street, Floor 5',
            '321 Business Avenue, Unit 10',
            '654 Enterprise Plaza, Wing B',
        ];

        return $addresses[$seed % count($addresses)];
    }

    private function seedInboundOrders(array $tenants, array $warehouses, array $skus, array $users): void
    {
        $createdByUser = $users[array_key_first($users)] ?? User::first();
        $statuses = [InboundOrder::STATUS_PENDING, InboundOrder::STATUS_ARRIVED, InboundOrder::STATUS_PARTIALLY_RECEIVED];

        foreach ($tenants as $tenantIdx => $tenant) {
            $tenantSkus = array_values(array_filter(
                $skus,
                fn ($sku) => $sku->tenant_id === $tenant->id && $sku->stock_item_id !== null
            ));

            if (empty($tenantSkus)) {
                continue;
            }

            foreach ($warehouses as $warehouseIdx => $warehouse) {
                for ($i = 0; $i < 3; $i++) {
                    $status = $statuses[($tenantIdx + $warehouseIdx + $i) % count($statuses)];

                    $inbound = InboundOrder::create([
                        'tenant_id' => $tenant->id,
                        'warehouse_id' => $warehouse->id,
                        'ref' => "IB-{$tenant->code}-{$warehouse->code}-".str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                        'status' => $status,
                        'expected_at' => now()->addDays(rand(1, 30)),
                        'note' => rand(0, 2) === 0 ? 'Urgent shipment' : '',
                        'created_by_user_id' => $createdByUser->id,
                    ]);

                    $lineCount = rand(2, 4);
                    $selectedSkus = array_slice(
                        $tenantSkus,
                        0,
                        min($lineCount, count($tenantSkus))
                    );

                    foreach ($selectedSkus as $sku) {
                        $expectedQty = rand(10, 100);
                        $receivedQty = $status === InboundOrder::STATUS_PENDING ? 0 : rand(0, $expectedQty);

                        InboundOrderLine::create([
                            'inbound_order_id' => $inbound->id,
                            'tenant_id' => $tenant->id,
                            'sku_id' => $sku->id,
                            'stock_item_id' => $sku->stock_item_id,
                            'expected_qty' => $expectedQty,
                            'received_qty' => $receivedQty,
                            'note' => rand(0, 3) === 0 ? 'Fragile' : '',
                        ]);
                    }
                }
            }
        }
    }

    private function seedOutboundOrders(array $tenants, array $warehouses, array $skus, array $users, array $shippingMethods): void
    {
        $createdByUser = $users[array_key_first($users)] ?? User::first();
        $courierMethods = array_values(array_filter(
            $shippingMethods,
            fn (ShippingMethod $method): bool => $method->supports_courier_csv,
        ));
        $couriers = ['FedEx', 'DHL', 'UPS', 'Local Delivery'];

        foreach ($tenants as $tenantIdx => $tenant) {
            $tenantSkus = array_filter($skus, fn ($s) => $s->tenant_id === $tenant->id);
            if (empty($tenantSkus)) {
                continue;
            }

            foreach ($warehouses as $warehouseIdx => $warehouse) {
                for ($i = 0; $i < 3; $i++) {
                    $shippingMethod = $courierMethods[($tenantIdx + $warehouseIdx + $i) % count($courierMethods)];
                    $courier = $couriers[($tenantIdx + $warehouseIdx + $i) % count($couriers)];
                    $isShipped = rand(0, 1) === 1;

                    $outbound = OutboundOrder::create([
                        'reason' => OutboundOrder::REASON_REPLACEMENT,
                        'ship_mode' => OutboundOrder::SHIP_MODE_PARCEL,
                        'tenant_id' => $tenant->id,
                        'warehouse_id' => $warehouse->id,
                        'ref' => "OB-{$tenant->code}-{$warehouse->code}-".str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                        'status' => $isShipped ? OutboundOrder::STATUS_SHIPPED : OutboundOrder::STATUS_PENDING,
                        'note' => rand(0, 2) === 0 ? 'Handle with care' : '',
                        'recipient_name' => $this->randomRecipientName($tenant->code, $i),
                        'recipient_phone' => $this->randomPhone(['JP', 'TH', 'SG'][($tenantIdx + $i) % 3]),
                        'recipient_country_code' => ['JP', 'TH', 'SG'][($tenantIdx + $i) % 3],
                        'recipient_postal_code' => ['100-0001', '188001', '018957'][($tenantIdx + $i) % 3],
                        'recipient_state' => ['Tokyo', 'Bangkok', 'Singapore'][($tenantIdx + $i) % 3],
                        'recipient_city' => ['Tokyo', 'Bangkok', 'Singapore'][($tenantIdx + $i) % 3],
                        'recipient_address_line1' => $this->randomAddress($i),
                        'recipient_address_line2' => 'Apt '.(100 + $i),
                        'shipping_method_id' => $shippingMethod->id,
                        'courier' => $courier,
                        'tracking_no' => $isShipped ? strtoupper($courier[0]).$warehouse->id.$tenant->id.str_pad($i, 6, '0', STR_PAD_LEFT) : null,
                        'package_count' => rand(1, 3),
                        'package_weight_g' => rand(500, 5000),
                        'ship_note' => rand(0, 3) === 0 ? 'Signature required' : '',
                        'shipped_at' => $isShipped ? now()->subDays(rand(1, 10)) : null,
                        'shipped_by_user_id' => $isShipped ? $createdByUser->id : null,
                        'created_by_user_id' => $createdByUser->id,
                    ]);

                    $lineCount = rand(1, 3);
                    $selectedSkus = array_slice(
                        array_values($tenantSkus),
                        0,
                        min($lineCount, count($tenantSkus))
                    );

                    foreach ($selectedSkus as $sku) {
                        OutboundOrderLine::create([
                            'outbound_order_id' => $outbound->id,
                            'parent_line_id' => null,
                            'tenant_id' => $tenant->id,
                            'sku_id' => $sku->id,
                            'stock_item_id' => $sku->stock_item_id,
                            'qty' => rand(1, 5),
                            'inventory_movement_id' => null,
                        ]);
                    }
                }
            }
        }
    }
}
