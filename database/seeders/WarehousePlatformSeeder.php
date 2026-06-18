<?php

namespace Database\Seeders;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\PackagingMaterial;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\SkuBundleComponent;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class WarehousePlatformSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::updateOrCreate(
            ['code' => 'ABC'],
            [
                'name' => 'ABC Trading Co., Ltd.',
                'contact_name' => 'Aiko Tanaka',
                'contact_email' => 'ops@example-tenant.test',
                'contact_phone' => '+81-3-1234-5678',
                'billing_terms' => 'net_30',
                'status' => 'active',
                'notes' => 'Demo tenant for overseas warehouse workflows.',
            ],
        );

        $internalAdmin = User::updateOrCreate(
            ['email' => 'admin@warehouse.test'],
            [
                'name' => 'Warehouse Admin',
                'password' => 'password',
                'user_type' => 'internal',
                'is_active' => true,
            ],
        );

        $tenantOwner = User::updateOrCreate(
            ['email' => 'owner@example-tenant.test'],
            [
                'name' => 'ABC Owner',
                'password' => 'password',
                'user_type' => 'tenant',
                'is_active' => true,
            ],
        );

        TenantUser::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'user_id' => $tenantOwner->id,
            ],
            [
                'role' => 'owner',
                'status' => 'active',
                'invited_at' => now()->subDays(14),
                'joined_at' => now()->subDays(13),
            ],
        );

        $amazonShop = Shop::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'code' => 'AMZ-JP-1',
            ],
            [
                'platform' => 'amazon',
                'marketplace' => 'JP',
                'name' => 'ABC Amazon JP',
                'contact_name' => 'Amazon Ops',
                'contact_email' => 'amazon-ops@example-tenant.test',
                'status' => 'active',
                'note' => 'Primary Amazon Japan shop.',
            ],
        );

        $shopifyShop = Shop::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'code' => 'SHP-JP-1',
            ],
            [
                'platform' => 'shopify',
                'marketplace' => 'JP',
                'name' => 'ABC Direct Store',
                'contact_name' => 'Direct Store Ops',
                'contact_email' => 'shopify@example-tenant.test',
                'status' => 'active',
                'note' => 'Direct-to-consumer Shopify store.',
            ],
        );

        $tokyoWarehouse = Warehouse::updateOrCreate(
            ['code' => 'JP-TOKYO-01'],
            [
                'name' => 'Tokyo Warehouse',
                'country_code' => 'JP',
                'timezone' => 'Asia/Tokyo',
                'postal_code' => '143-0001',
                'state' => 'Tokyo',
                'city' => 'Ota',
                'address_line1' => '1-1-1 Heiwajima',
                'address_line2' => null,
                'phone' => '+81-3-5555-0101',
                'status' => 'active',
            ],
        );

        Warehouse::updateOrCreate(
            ['code' => 'CN-SZ-01'],
            [
                'name' => 'Shenzhen Warehouse',
                'country_code' => 'CN',
                'timezone' => 'Asia/Shanghai',
                'postal_code' => '518000',
                'state' => 'Guangdong',
                'city' => 'Shenzhen',
                'address_line1' => '88 Logistics Road',
                'address_line2' => null,
                'phone' => '+86-755-5555-0101',
                'status' => 'active',
            ],
        );

        $boxM = PackagingMaterial::updateOrCreate(
            ['code' => 'BOX-M'],
            [
                'name' => 'Medium Carton Box',
                'type' => 'box',
                'length_value' => 35,
                'width_value' => 25,
                'height_value' => 15,
                'dimension_unit' => 'cm',
                'weight_value' => 180,
                'weight_unit' => 'g',
                'cost' => 90,
                'currency' => 'JPY',
                'status' => 'active',
                'note' => 'Default carton for small electronics.',
            ],
        );

        $bubbleMailer = PackagingMaterial::updateOrCreate(
            ['code' => 'BUBBLE-MAILER-A4'],
            [
                'name' => 'A4 Bubble Mailer',
                'type' => 'mailer',
                'length_value' => 33,
                'width_value' => 24,
                'height_value' => 2,
                'dimension_unit' => 'cm',
                'weight_value' => 35,
                'weight_unit' => 'g',
                'cost' => 28,
                'currency' => 'JPY',
                'status' => 'active',
                'note' => 'Lightweight padded mailer.',
            ],
        );

        $charger = StockItem::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'code' => 'STK-000001',
            ],
            [
                'name' => 'USB-C Fast Charger 30W Black',
                'short_name' => '30W Charger',
                'brand' => 'ABC Gear',
                'model_number' => 'CHG-30W-BLK',
                'variation_code' => 'BLK',
                'color' => 'Black',
                'size' => null,
                'barcode' => '4901234567894',
                'barcode_type' => 'jan',
                'product_type' => 'normal',
                'is_dangerous_goods' => false,
                'requires_expiry_tracking' => false,
                'requires_lot_tracking' => false,
                'description' => 'Compact 30W USB-C wall charger.',
                'note' => null,
                'handling_note' => 'Keep dry.',
                'weight_value' => 86,
                'weight_unit' => 'g',
                'length_value' => 4.2,
                'width_value' => 3.8,
                'height_value' => 2.9,
                'dimension_unit' => 'cm',
                'status' => 'active',
            ],
        );

        $cable = StockItem::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'code' => 'STK-000002',
            ],
            [
                'name' => 'USB-C Cable 1m White',
                'short_name' => 'USB-C Cable',
                'brand' => 'ABC Gear',
                'model_number' => 'CBL-C-1M-WHT',
                'variation_code' => 'WHT-1M',
                'color' => 'White',
                'size' => '1m',
                'barcode' => '4901234567900',
                'barcode_type' => 'jan',
                'product_type' => 'normal',
                'is_dangerous_goods' => false,
                'requires_expiry_tracking' => false,
                'requires_lot_tracking' => false,
                'description' => 'Durable 1m USB-C charging cable.',
                'note' => null,
                'handling_note' => null,
                'weight_value' => 42,
                'weight_unit' => 'g',
                'length_value' => 12,
                'width_value' => 8,
                'height_value' => 1.5,
                'dimension_unit' => 'cm',
                'status' => 'active',
            ],
        );

        $chargerSku = Sku::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'shop_id' => $amazonShop->id,
                'sku' => 'ABC-CHG30-BLK',
            ],
            [
                'stock_item_id' => $charger->id,
                'name' => 'USB-C Fast Charger 30W - Black',
                'platform_sku' => 'ABC-CHG30-BLK',
                'platform_product_id' => 'B0ABCCHG30',
                'platform_variant_id' => null,
                'platform_variant_name' => 'Black',
                'platform_label_code' => 'X00ABCCHG30',
                'sku_type' => 'single',
                'default_packaging_material_id' => $bubbleMailer->id,
                'status' => 'active',
                'note' => null,
            ],
        );

        $cableSku = Sku::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'shop_id' => $shopifyShop->id,
                'sku' => 'ABC-CABLE-C-1M-WHT',
            ],
            [
                'stock_item_id' => $cable->id,
                'name' => 'USB-C Cable 1m - White',
                'platform_sku' => 'ABC-CABLE-C-1M-WHT',
                'platform_product_id' => 'gid://shopify/Product/10001',
                'platform_variant_id' => 'gid://shopify/ProductVariant/20001',
                'platform_variant_name' => 'White / 1m',
                'platform_label_code' => null,
                'sku_type' => 'single',
                'default_packaging_material_id' => $bubbleMailer->id,
                'status' => 'active',
                'note' => null,
            ],
        );

        $bundleSku = Sku::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'shop_id' => $shopifyShop->id,
                'sku' => 'ABC-STARTER-KIT',
            ],
            [
                'stock_item_id' => null,
                'name' => 'USB-C Starter Kit',
                'platform_sku' => 'ABC-STARTER-KIT',
                'platform_product_id' => 'gid://shopify/Product/10002',
                'platform_variant_id' => 'gid://shopify/ProductVariant/20002',
                'platform_variant_name' => 'Default',
                'platform_label_code' => null,
                'sku_type' => 'virtual_bundle',
                'default_packaging_material_id' => $boxM->id,
                'status' => 'active',
                'note' => 'Virtual bundle deducting charger and cable stock pools.',
            ],
        );

        SkuBundleComponent::updateOrCreate(
            [
                'bundle_sku_id' => $bundleSku->id,
                'component_stock_item_id' => $charger->id,
            ],
            [
                'tenant_id' => $tenant->id,
                'quantity' => 1,
            ],
        );

        SkuBundleComponent::updateOrCreate(
            [
                'bundle_sku_id' => $bundleSku->id,
                'component_stock_item_id' => $cable->id,
            ],
            [
                'tenant_id' => $tenant->id,
                'quantity' => 1,
            ],
        );

        $inventoryService = app(InventoryService::class);

        $this->seedInventoryBalance($inventoryService, $tenant->id, $tokyoWarehouse->id, $charger->id, 120, 18, 6, 1);
        $this->seedInventoryBalance($inventoryService, $tenant->id, $tokyoWarehouse->id, $cable->id, 240, 35, 10, 2);
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

        if (! InventoryMovement::where('ref_type', 'demo_seed')->where('ref_id', $referenceNumber)->exists()) {
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
}
