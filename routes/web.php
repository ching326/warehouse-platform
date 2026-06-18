<?php

use App\Livewire\InventoryIndex;
use App\Livewire\InventoryMovementsIndex;
use App\Livewire\SkuCreate;
use App\Livewire\SkusIndex;
use App\Livewire\StockAdjustmentCreate;
use Illuminate\Support\Facades\Route;

Route::post('/locale/{locale}', \App\Http\Controllers\LocaleController::class)
    ->name('locale.switch');

Route::get('/', InventoryIndex::class);
Route::get('/inventory', InventoryIndex::class)->name('inventory.index');
Route::get('/inventory/movements', InventoryMovementsIndex::class)->name('inventory.movements.index');
Route::get('/skus', SkusIndex::class)->name('skus.index');
Route::get('/skus/create', SkuCreate::class)->name('skus.create');
Route::get('/stock-adjustments/create', StockAdjustmentCreate::class)->name('stock-adjustments.create');
