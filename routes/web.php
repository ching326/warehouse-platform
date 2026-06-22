<?php

use App\Http\Controllers\SalesOrderExportController;
use App\Http\Controllers\CourierExportDownloadController;
use App\Http\Controllers\FulfillmentTrackingImportController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MarketplaceShippingNoticeDownloadController;
use App\Livewire\FulfillmentGroupCreate;
use App\Livewire\FulfillmentGroupDetail;
use App\Livewire\FulfillmentGroupIndex;
use App\Livewire\FulfillmentGroupPack;
use App\Livewire\FulfillmentPackStart;
use App\Livewire\IssueCreate;
use App\Livewire\IssueIndex;
use App\Livewire\IssueShow;
use App\Livewire\InventoryIndex;
use App\Livewire\InventoryMovementsIndex;
use App\Livewire\InboundOrderCreate;
use App\Livewire\InboundOrderDetail;
use App\Livewire\InboundOrderIndex;
use App\Livewire\InboundOrderReceive;
use App\Livewire\ReturnOrderCreate;
use App\Livewire\ReturnOrderDisposition;
use App\Livewire\ReturnOrderIndex;
use App\Livewire\ReturnOrderInspect;
use App\Livewire\ReturnOrderReceive;
use App\Livewire\ReturnOrderShow;
use App\Livewire\OutboundOrderCreate;
use App\Livewire\OutboundOrderDetail;
use App\Livewire\OutboundOrderIndex;
use App\Livewire\OutboundOrderShip;
use App\Livewire\AmazonSpapiOrderImport;
use App\Livewire\SalesOrderCreate;
use App\Livewire\SalesOrderDetail;
use App\Livewire\SalesOrderImport;
use App\Livewire\SalesOrderIndex;
use App\Livewire\ShopCreate;
use App\Livewire\ShopEdit;
use App\Livewire\ShopIndex;
use App\Livewire\ShippingMethodCreate;
use App\Livewire\ShippingMethodEdit;
use App\Livewire\ShippingMethodIndex;
use App\Livewire\SkuCreate;
use App\Livewire\SkusIndex;
use App\Livewire\StockAdjustmentCreate;
use App\Livewire\TenantCreate;
use App\Livewire\TenantEdit;
use App\Livewire\TenantIndex;
use App\Livewire\WarehouseCreate;
use App\Livewire\WarehouseEdit;
use App\Livewire\WarehouseIndex;
use App\Livewire\OtherSettings;
use App\Livewire\PackagingMaterialCreate;
use App\Livewire\PackagingMaterialEdit;
use App\Livewire\PackagingMaterialIndex;
use App\Livewire\WarehouseLocationCreate;
use App\Livewire\WarehouseLocationEdit;
use App\Livewire\WarehouseLocationIndex;
use Illuminate\Support\Facades\Route;

Route::post('/locale/{locale}', \App\Http\Controllers\LocaleController::class)
    ->name('locale.switch');

if (app()->environment('local')) {
    Route::get('/dev-login', function () {
        $user = \App\Models\User::query()
            ->where('user_type', 'internal')
            ->where('is_active', true)
            ->first();

        if (! $user) {
            $user = \App\Models\User::create([
                'name' => 'Warehouse Admin',
                'email' => 'admin@warehouse.test',
                'password' => 'password',
                'user_type' => 'internal',
                'is_active' => true,
            ]);
        }

        \Illuminate\Support\Facades\Auth::login($user);
        request()->session()->regenerate();

        return redirect()->intended(route('inventory.index'));
    })->name('dev.login');

    Route::get('/dev-logout', function () {
        \Illuminate\Support\Facades\Auth::logout();
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
    Route::get('/inbound/create', InboundOrderCreate::class)->name('inbound.create');
    Route::get('/inbound/{order}/receive', InboundOrderReceive::class)->name('inbound.receive');
    Route::get('/inbound/{order}', InboundOrderDetail::class)->name('inbound.show');
    Route::get('/return-orders', ReturnOrderIndex::class)->name('return-orders.index');
    Route::get('/return-orders/create', ReturnOrderCreate::class)->name('return-orders.create');
    Route::get('/return-orders/{returnOrder}/receive', ReturnOrderReceive::class)->name('return-orders.receive');
    Route::get('/return-orders/{returnOrder}/inspect', ReturnOrderInspect::class)->name('return-orders.inspect');
    Route::get('/return-orders/{returnOrder}/disposition', ReturnOrderDisposition::class)->name('return-orders.disposition');
    Route::get('/return-orders/{returnOrder}', ReturnOrderShow::class)->name('return-orders.show');
    Route::get('/outbound', OutboundOrderIndex::class)->name('outbound.index');
    Route::get('/outbound/create', OutboundOrderCreate::class)->name('outbound.create');
    Route::get('/outbound/{order}/ship', OutboundOrderShip::class)->name('outbound.ship');
    Route::get('/outbound/{order}', OutboundOrderDetail::class)->name('outbound.show');
    Route::get('/sales-orders', SalesOrderIndex::class)->name('sales.orders.index');
    Route::get('/sales-orders/create', SalesOrderCreate::class)->name('sales.orders.create');
    Route::get('/sales-orders/import', SalesOrderImport::class)->name('sales.orders.import');
    Route::get('/sales-orders/import/amazon-api', AmazonSpapiOrderImport::class)->name('sales.orders.import.amazon-api');
    Route::get('/sales-orders/export', SalesOrderExportController::class)->name('sales.orders.export');
    Route::get('/sales-orders/{order}/issues/create', IssueCreate::class)->name('sales.orders.issues.create');
    Route::get('/sales-orders/{order}', SalesOrderDetail::class)->name('sales.orders.show');
    Route::get('/courier-export-batches/{batch}/download', CourierExportDownloadController::class)->name('courier-export-batches.download');
    Route::get('/marketplace-shipping-notice-batches/{batch}/download', MarketplaceShippingNoticeDownloadController::class)
        ->name('marketplace-shipping-notice-batches.download');
    Route::get('/fulfillment-groups', FulfillmentGroupIndex::class)->name('fulfillment-groups.index');
    Route::get('/fulfillment/pack', FulfillmentPackStart::class)->name('fulfillment.pack.start');
    Route::post('/fulfillment-groups/tracking-import', FulfillmentTrackingImportController::class)->name('fulfillment.tracking-import');
    Route::get('/fulfillment-groups/create', FulfillmentGroupCreate::class)->name('fulfillment-groups.create');
    Route::get('/fulfillment-groups/{group}/pack', FulfillmentGroupPack::class)->name('fulfillment-groups.pack');
    Route::get('/fulfillment-groups/{group}', FulfillmentGroupDetail::class)->name('fulfillment-groups.show');
    Route::get('/issues', IssueIndex::class)->name('issues.index');
    Route::get('/issues/create', IssueCreate::class)->name('issues.create');
    Route::get('/issues/{issue}', IssueShow::class)->name('issues.show');
    Route::get('/skus', SkusIndex::class)->name('skus.index');
    Route::get('/skus/create', SkuCreate::class)->name('skus.create');
    Route::get('/setup/tenants', TenantIndex::class)->name('setup.tenants.index');
    Route::get('/setup/tenants/create', TenantCreate::class)->name('setup.tenants.create');
    Route::get('/setup/tenants/{tenant}/edit', TenantEdit::class)->name('setup.tenants.edit');
    Route::get('/setup/warehouses', WarehouseIndex::class)->name('setup.warehouses.index');
    Route::get('/setup/warehouses/create', WarehouseCreate::class)->name('setup.warehouses.create');
    Route::get('/setup/warehouses/{warehouse}/edit', WarehouseEdit::class)->name('setup.warehouses.edit');
    Route::get('/setup/shops', ShopIndex::class)->name('setup.shops.index');
    Route::get('/setup/shops/create', ShopCreate::class)->name('setup.shops.create');
    Route::get('/setup/shops/{shop}/edit', ShopEdit::class)->name('setup.shops.edit');
    Route::get('/setup/shipping-methods', ShippingMethodIndex::class)->name('setup.shipping-methods.index');
    Route::get('/setup/shipping-methods/create', ShippingMethodCreate::class)->name('setup.shipping-methods.create');
    Route::get('/setup/shipping-methods/{method}/edit', ShippingMethodEdit::class)->name('setup.shipping-methods.edit');
    Route::get('/setup/locations', WarehouseLocationIndex::class)->name('setup.locations.index');
    Route::get('/setup/locations/create', WarehouseLocationCreate::class)->name('setup.locations.create');
    Route::get('/setup/locations/{location}/edit', WarehouseLocationEdit::class)->name('setup.locations.edit');
    Route::get('/setup/packagings', PackagingMaterialIndex::class)->name('setup.packagings.index');
    Route::get('/setup/packagings/create', PackagingMaterialCreate::class)->name('setup.packagings.create');
    Route::get('/setup/packagings/{packaging}/edit', PackagingMaterialEdit::class)->name('setup.packagings.edit');
    Route::get('/setup/other-settings', OtherSettings::class)->name('setup.other-settings');
    Route::get('/stock-adjustments/create', StockAdjustmentCreate::class)->name('stock-adjustments.create');
});


