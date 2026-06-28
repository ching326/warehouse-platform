<?php

namespace App\Livewire\Concerns;

use App\Models\Warehouse;

trait AutoSelectsSingleActiveWarehouse
{
    protected function autoSelectSingleActiveWarehouse(): void
    {
        if ($this->warehouseId !== '') {
            return;
        }

        $activeWarehouseIds = Warehouse::query()
            ->where('status', 'active')
            ->pluck('id');

        if ($activeWarehouseIds->count() === 1) {
            $this->warehouseId = (string) $activeWarehouseIds->first();
        }
    }
}
