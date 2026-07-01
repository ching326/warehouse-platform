<?php

namespace App\Livewire;

use App\Models\StockCountRun;
use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class StockCountShow extends Component
{
    public StockCountRun $stockCountRun;

    public function mount(StockCountRun $stockCountRun): void
    {
        if (! in_array((int) $stockCountRun->tenant_id, $this->allowedTenantIds(), true)) {
            abort(404);
        }

        $this->stockCountRun = $stockCountRun;
    }

    public function render(): View
    {
        $run = $this->stockCountRun->load([
            'tenant:id,code,name',
            'warehouse:id,code,name',
            'createdBy:id,name',
            'lines.stockItem:id,code,tenant_item_code,short_name,'.implode(',', StockItem::DISPLAY_NAME_COLUMNS),
            'lines.movement:id',
        ]);

        return view('livewire.stock-count-show', [
            'run' => $run,
        ])->layout('inventory', [
            'title' => __('stock_counts.show_title', ['id' => $run->id]),
            'subtitle' => __('stock_counts.page_subtitle'),
        ]);
    }

    private function allowedTenantIds(): array
    {
        if (Auth::user()?->user_type === 'internal') {
            return Tenant::query()->pluck('id')->all();
        }

        return Auth::user()?->activeTenantIds() ?? [];
    }
}
