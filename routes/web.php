<?php

use App\Http\Controllers\CourierExportDownloadController;
use App\Http\Controllers\FulfillmentTrackingImportController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MarketplaceShippingNoticeDownloadController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\SalesOrderExportController;
use App\Http\Controllers\SkuLabelController;
use App\Livewire\AmazonSpapiOrderImport;
use App\Livewire\BillingRunIndex;
use App\Livewire\FbaWarehouseCreate;
use App\Livewire\FbaWarehouseEdit;
use App\Livewire\FbaWarehouseIndex;
use App\Livewire\FeeRateCreate;
use App\Livewire\FeeRateEdit;
use App\Livewire\FeeRateIndex;
use App\Livewire\FulfillmentIndex;
use App\Livewire\FulfillmentPack;
use App\Livewire\FulfillmentPackScanIndex;
use App\Livewire\FulfillmentPackStart;
use App\Livewire\FulfillmentPickSummary;
use App\Livewire\FulfillmentPrintHistory;
use App\Livewire\InboundOrderCreate;
use App\Livewire\InboundOrderDetail;
use App\Livewire\InboundOrderIndex;
use App\Livewire\InboundOrderReceive;
use App\Livewire\InventoryIndex;
use App\Livewire\InventoryMovementsIndex;
use App\Livewire\IssueCreate;
use App\Livewire\IssueIndex;
use App\Livewire\IssueShow;
use App\Livewire\OtherSettings;
use App\Livewire\OutboundOrderCreate;
use App\Livewire\OutboundOrderDetail;
use App\Livewire\OutboundOrderIndex;
use App\Livewire\OutboundOrderShip;
use App\Livewire\PackagingMaterialCreate;
use App\Livewire\PackagingMaterialEdit;
use App\Livewire\PackagingMaterialIndex;
use App\Livewire\ProductTypeSettings;
use App\Livewire\ReturnOrderCreate;
use App\Livewire\ReturnOrderDisposition;
use App\Livewire\ReturnOrderIndex;
use App\Livewire\ReturnOrderInspect;
use App\Livewire\ReturnOrderReceive;
use App\Livewire\ReturnOrderShow;
use App\Livewire\SalesOrderCreate;
use App\Livewire\SalesOrderDetail;
use App\Livewire\SalesOrderImport;
use App\Livewire\SalesOrderIndex;
use App\Livewire\SalesOrderPasteImport;
use App\Livewire\ShippingMethodCreate;
use App\Livewire\ShippingMethodEdit;
use App\Livewire\ShippingMethodIndex;
use App\Livewire\ShopCreate;
use App\Livewire\ShopEdit;
use App\Livewire\ShopIndex;
use App\Livewire\SkuCreate;
use App\Livewire\SkuEdit;
use App\Livewire\SkuImport;
use App\Livewire\SkuLabelPrint;
use App\Livewire\SkusIndex;
use App\Livewire\StockAdjustmentCreate;
use App\Livewire\StockAdjustmentImport;
use App\Livewire\StockCountCreate;
use App\Livewire\StockCountImport;
use App\Livewire\StockCountIndex;
use App\Livewire\StockCountShow;
use App\Livewire\TenantCreate;
use App\Livewire\TenantEdit;
use App\Livewire\TenantIndex;
use App\Livewire\TenantTeam;
use App\Livewire\UserCreate;
use App\Livewire\UserEdit;
use App\Livewire\UserIndex;
use App\Livewire\WarehouseCreate;
use App\Livewire\WarehouseEdit;
use App\Livewire\WarehouseIndex;
use App\Livewire\WarehouseLocationCreate;
use App\Livewire\WarehouseLocationEdit;
use App\Livewire\WarehouseLocationIndex;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::post('/locale/{locale}', LocaleController::class)
    ->name('locale.switch');

if (app()->environment('local')) {
    Route::get('/dev-login', function () {
        $user = User::query()
            ->where('user_type', 'internal')
            ->where('is_active', true)
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => 'Warehouse Admin',
                'email' => 'admin@warehouse.test',
                'password' => 'password',
                'user_type' => 'internal',
                'role' => User::ROLE_INTERNAL_ADMIN,
                'is_active' => true,
            ]);
        }

        Auth::login($user);
        request()->session()->regenerate();

        return redirect()->intended(route('inventory.index'));
    })->name('dev.login');

    Route::get('/dev-logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('dev.login');
    })->name('dev.logout');
}

Route::middleware('authenticated')->group(function (): void {
    Route::get('/media/{mediaAsset}', MediaController::class)->name('media.show');
    Route::get('/', InventoryIndex::class);
    Route::get('/inventory', InventoryIndex::class)->name('inventory.index');
    Route::get('/inventory/movements', InventoryMovementsIndex::class)->name('inventory.movements.index');
    Route::get('/inbound', InboundOrderIndex::class)->name('inbound.index');
    Route::get('/inbound/create', InboundOrderCreate::class)->middleware('capability:operate_warehouse')->name('inbound.create');
    Route::get('/inbound/{order}/receive', InboundOrderReceive::class)->middleware('capability:operate_warehouse')->name('inbound.receive');
    Route::get('/inbound/{order}', InboundOrderDetail::class)->name('inbound.show');
    Route::get('/return-orders', ReturnOrderIndex::class)->name('return-orders.index');
    Route::get('/return-orders/create', ReturnOrderCreate::class)->name('return-orders.create');
    Route::get('/return-orders/{returnOrder}/receive', ReturnOrderReceive::class)->middleware('capability:operate_warehouse')->name('return-orders.receive');
    Route::get('/return-orders/{returnOrder}/inspect', ReturnOrderInspect::class)->middleware('capability:operate_warehouse')->name('return-orders.inspect');
    Route::get('/return-orders/{returnOrder}/disposition', ReturnOrderDisposition::class)->middleware('capability:operate_warehouse')->name('return-orders.disposition');
    Route::get('/return-orders/{returnOrder}', ReturnOrderShow::class)->name('return-orders.show');
    Route::get('/outbound', OutboundOrderIndex::class)->name('outbound.index');
    Route::get('/outbound/create', OutboundOrderCreate::class)->name('outbound.create');
    Route::get('/outbound/{order}/direct-pack', OutboundOrderShip::class)->middleware('capability:operate_warehouse')->name('outbound.ship');
    Route::get('/outbound/{order}/scan-pack', FulfillmentPack::class)->middleware('capability:operate_warehouse')->name('outbound.pack');
    Route::get('/outbound/{order}', OutboundOrderDetail::class)->name('outbound.show');
    Route::get('/sales-orders', SalesOrderIndex::class)->name('sales.orders.index');
    Route::get('/sales-orders/create', SalesOrderCreate::class)->name('sales.orders.create');
    Route::get('/sales-orders/import', SalesOrderImport::class)->name('sales.orders.import');
    Route::get('/sales-orders/import/paste', SalesOrderPasteImport::class)->name('sales.orders.import.paste');
    Route::get('/sales-orders/import/amazon-api', AmazonSpapiOrderImport::class)->middleware('capability:manage_api_credentials')->name('sales.orders.import.amazon-api');
    Route::get('/sales-orders/export', SalesOrderExportController::class)->name('sales.orders.export');
    Route::get('/sales-orders/{order}/issues/create', IssueCreate::class)->name('sales.orders.issues.create');
    Route::get('/sales-orders/{order}', SalesOrderDetail::class)->name('sales.orders.show');
    Route::get('/courier-export-batches/{batch}/download', CourierExportDownloadController::class)->name('courier-export-batches.download');
    Route::get('/marketplace-shipping-notice-batches/{batch}/download', MarketplaceShippingNoticeDownloadController::class)
        ->name('marketplace-shipping-notice-batches.download');
    Route::get('/fulfillment', FulfillmentIndex::class)->middleware('capability:operate_warehouse')->name('fulfillment.index');
    Route::get('/fulfillment/print-history', FulfillmentPrintHistory::class)->middleware('capability:operate_warehouse')->name('fulfillment.print-history');
    Route::get('/fulfillment/pick-summary', FulfillmentPickSummary::class)->middleware('capability:operate_warehouse')->name('fulfillment.pick-summary');
    Route::get('/fulfillment/scan-pack', FulfillmentPackStart::class)->middleware('capability:operate_warehouse')->name('fulfillment.pack.start');
    Route::get('/fulfillment/pack-scans', FulfillmentPackScanIndex::class)->middleware('capability:operate_warehouse')->name('fulfillment.pack-scans.index');
    Route::post('/fulfillment/tracking-import', FulfillmentTrackingImportController::class)->middleware('capability:export_courier_labels')->name('fulfillment.tracking-import');
    Route::get('/issues', IssueIndex::class)->name('issues.index');
    Route::get('/issues/create', IssueCreate::class)->name('issues.create');
    Route::get('/issues/{issue}', IssueShow::class)->name('issues.show');
    Route::get('/skus', SkusIndex::class)->name('skus.index');
    Route::get('/skus/create', SkuCreate::class)->name('skus.create');
    Route::get('/skus/import', SkuImport::class)->name('skus.import');
    Route::get('/skus/labels/print', SkuLabelPrint::class)->name('skus.label.print');
    Route::get('/skus/labels/download', [SkuLabelController::class, 'download'])->name('skus.label.download');
    Route::get('/skus/{sku}/label', SkuLabelPrint::class)->name('skus.label');
    Route::get('/skus/{sku}/edit', SkuEdit::class)->name('skus.edit');
    Route::get('/team', TenantTeam::class)->middleware('capability:manage_tenant_members')->name('team.index');
    Route::get('/setup/users', UserIndex::class)->middleware('capability:manage_users')->name('setup.users.index');
    Route::get('/setup/users/create', UserCreate::class)->middleware('capability:manage_users')->name('setup.users.create');
    Route::get('/setup/users/{user}/edit', UserEdit::class)->middleware('capability:manage_users')->name('setup.users.edit');
    Route::get('/setup/tenants', TenantIndex::class)->middleware('capability:manage_setup')->name('setup.tenants.index');
    Route::get('/setup/tenants/create', TenantCreate::class)->middleware('capability:manage_setup')->name('setup.tenants.create');
    Route::get('/setup/tenants/{tenant}/edit', TenantEdit::class)->middleware('capability:manage_setup')->name('setup.tenants.edit');
    Route::get('/setup/warehouses', WarehouseIndex::class)->middleware('capability:manage_setup')->name('setup.warehouses.index');
    Route::get('/setup/warehouses/create', WarehouseCreate::class)->middleware('capability:manage_setup')->name('setup.warehouses.create');
    Route::get('/setup/warehouses/{warehouse}/edit', WarehouseEdit::class)->middleware('capability:manage_setup')->name('setup.warehouses.edit');
    Route::get('/setup/fba-warehouses', FbaWarehouseIndex::class)->middleware('capability:manage_setup')->name('setup.fba-warehouses.index');
    Route::get('/setup/fba-warehouses/create', FbaWarehouseCreate::class)->middleware('capability:manage_setup')->name('setup.fba-warehouses.create');
    Route::get('/setup/fba-warehouses/{fbaWarehouse}/edit', FbaWarehouseEdit::class)->middleware('capability:manage_setup')->name('setup.fba-warehouses.edit');
    Route::get('/setup/shops', ShopIndex::class)->middleware('capability:manage_setup')->name('setup.shops.index');
    Route::get('/setup/shops/create', ShopCreate::class)->middleware('capability:manage_setup')->name('setup.shops.create');
    Route::get('/setup/shops/{shop}/edit', ShopEdit::class)->middleware('capability:manage_setup')->name('setup.shops.edit');
    Route::get('/setup/shipping-methods', ShippingMethodIndex::class)->middleware('capability:manage_setup')->name('setup.shipping-methods.index');
    Route::get('/setup/shipping-methods/create', ShippingMethodCreate::class)->middleware('capability:manage_setup')->name('setup.shipping-methods.create');
    Route::get('/setup/shipping-methods/{method}/edit', ShippingMethodEdit::class)->middleware('capability:manage_setup')->name('setup.shipping-methods.edit');
    Route::get('/setup/fee-rates', FeeRateIndex::class)->middleware('capability:manage_billing')->name('setup.fee-rates.index');
    Route::get('/setup/fee-rates/create', FeeRateCreate::class)->middleware('capability:manage_billing')->name('setup.fee-rates.create');
    Route::get('/setup/fee-rates/{feeRate}/edit', FeeRateEdit::class)->middleware('capability:manage_billing')->name('setup.fee-rates.edit');
    Route::get('/setup/billing', BillingRunIndex::class)->middleware('capability:manage_billing')->name('setup.billing.index');
    Route::get('/setup/locations', WarehouseLocationIndex::class)->middleware('capability:manage_setup')->name('setup.locations.index');
    Route::get('/setup/locations/create', WarehouseLocationCreate::class)->middleware('capability:manage_setup')->name('setup.locations.create');
    Route::get('/setup/locations/{location}/edit', WarehouseLocationEdit::class)->middleware('capability:manage_setup')->name('setup.locations.edit');
    Route::get('/setup/packagings', PackagingMaterialIndex::class)->middleware('capability:manage_setup')->name('setup.packagings.index');
    Route::get('/setup/packagings/create', PackagingMaterialCreate::class)->middleware('capability:manage_setup')->name('setup.packagings.create');
    Route::get('/setup/packagings/{packaging}/edit', PackagingMaterialEdit::class)->middleware('capability:manage_setup')->name('setup.packagings.edit');
    Route::get('/setup/other-settings', OtherSettings::class)->middleware('capability:manage_setup')->name('setup.other-settings');
    Route::get('/setup/product-types', ProductTypeSettings::class)->middleware('capability:manage_setup')->name('setup.product-types.index');
    Route::get('/stock-adjustments/create', StockAdjustmentCreate::class)->middleware('capability:mutate_inventory')->name('stock-adjustments.create');
    Route::get('/stock-adjustments/import', StockAdjustmentImport::class)->middleware('capability:mutate_inventory')->name('stock-adjustments.import');
    Route::get('/inventory/stock-counts', StockCountIndex::class)->middleware('capability:mutate_inventory')->name('stock-counts.index');
    Route::get('/inventory/stock-counts/create', StockCountCreate::class)->middleware('capability:mutate_inventory')->name('stock-counts.create');
    Route::get('/inventory/stock-counts/import', StockCountImport::class)->middleware('capability:mutate_inventory')->name('stock-counts.import');
    Route::get('/inventory/stock-counts/{stockCountRun}', StockCountShow::class)->middleware('capability:mutate_inventory')->name('stock-counts.show');
});
