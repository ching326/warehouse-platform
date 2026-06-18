<?php

use App\Livewire\InventoryIndex;
use App\Livewire\InventoryMovementsIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', InventoryIndex::class);
Route::get('/inventory', InventoryIndex::class)->name('inventory.index');
Route::get('/inventory/movements', InventoryMovementsIndex::class)->name('inventory.movements.index');
