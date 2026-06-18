<?php

use App\Livewire\InventoryIndex;
use App\Livewire\InventoryMovementsIndex;
use App\Livewire\InboundOrderCreate;
use App\Livewire\InboundOrderIndex;
use App\Livewire\InboundOrderReceive;
use App\Livewire\OutboundOrderCreate;
use App\Livewire\OutboundOrderIndex;
use App\Livewire\OutboundOrderShip;
use App\Livewire\SkuCreate;
use App\Livewire\SkusIndex;
use App\Livewire\StockAdjustmentCreate;
use App\Livewire\WarehouseLocationCreate;
use App\Livewire\WarehouseLocationIndex;
use Illuminate\Support\Facades\Route;

Route::post('/locale/{locale}', \App\Http\Controllers\LocaleController::class)
    ->name('locale.switch');

Route::get('/', InventoryIndex::class);
Route::get('/inventory', InventoryIndex::class)->name('inventory.index');
Route::get('/inventory/movements', InventoryMovementsIndex::class)->name('inventory.movements.index');
Route::get('/inbound', InboundOrderIndex::class)->name('inbound.index');
Route::get('/inbound/create', InboundOrderCreate::class)->name('inbound.create');
Route::get('/inbound/{order}/receive', InboundOrderReceive::class)->name('inbound.receive');
Route::get('/outbound', OutboundOrderIndex::class)->name('outbound.index');
Route::get('/outbound/create', OutboundOrderCreate::class)->name('outbound.create');
Route::get('/outbound/{order}/ship', OutboundOrderShip::class)->name('outbound.ship');
Route::get('/skus', SkusIndex::class)->name('skus.index');
Route::get('/skus/create', SkuCreate::class)->name('skus.create');
Route::get('/setup/locations', WarehouseLocationIndex::class)->name('setup.locations.index');
Route::get('/setup/locations/create', WarehouseLocationCreate::class)->name('setup.locations.create');
Route::get('/stock-adjustments/create', StockAdjustmentCreate::class)->name('stock-adjustments.create');
