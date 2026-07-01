<?php

namespace App\Livewire;

use App\Models\StockCountRun;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class StockCountIndex extends Component
{
    use WithPagination;

    public function render(): View
    {
        $runs = StockCountRun::query()
            ->with(['tenant:id,code,name', 'warehouse:id,code,name', 'createdBy:id,name'])
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->latest()
            ->paginate(25);

        return view('livewire.stock-count-index', [
            'runs' => $runs,
        ])->layout('inventory', [
            'title' => __('stock_counts.page_title'),
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
