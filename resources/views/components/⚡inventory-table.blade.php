<?php

use App\Models\InventoryItem;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $sortField = 'sku';

    public string $sortDirection = 'asc';

    public int $perPage = 8;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['sku', 'name', 'location', 'quantity', 'status'], true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = 'asc';
    }

    public function items()
    {
        return InventoryItem::query()
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    $query
                        ->where('sku', 'like', "%{$this->search}%")
                        ->orWhere('name', 'like', "%{$this->search}%")
                        ->orWhere('location', 'like', "%{$this->search}%");
                });
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function counts(): array
    {
        return [
            'total' => InventoryItem::count(),
            'low' => InventoryItem::where('status', 'low_stock')->count(),
            'out' => InventoryItem::where('status', 'out_of_stock')->count(),
        ];
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'low_stock' => 'Low stock',
            'out_of_stock' => 'Out of stock',
            default => 'In stock',
        };
    }

    public function statusClass(string $status): string
    {
        return match ($status) {
            'low_stock' => 'badge badge-warning',
            'out_of_stock' => 'badge badge-danger',
            default => 'badge badge-success',
        };
    }
};
?>

<div class="inventory-table">
    @php
        $items = $this->items();
        $counts = $this->counts();
    @endphp

    <section class="summary-grid" aria-label="Inventory summary">
        <div class="summary-card">
            <span>Total SKUs</span>
            <strong>{{ number_format($counts['total']) }}</strong>
        </div>
        <div class="summary-card">
            <span>Low Stock</span>
            <strong>{{ number_format($counts['low']) }}</strong>
        </div>
        <div class="summary-card">
            <span>Out of Stock</span>
            <strong>{{ number_format($counts['out']) }}</strong>
        </div>
    </section>

    <section class="table-shell">
        <div class="table-toolbar">
            <label>
                <span>Search inventory</span>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="SKU, item, or location"
                >
            </label>

            <label>
                <span>Status</span>
                <select wire:model.live="status">
                    <option value="">All statuses</option>
                    <option value="in_stock">In stock</option>
                    <option value="low_stock">Low stock</option>
                    <option value="out_of_stock">Out of stock</option>
                </select>
            </label>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>
                            <button type="button" wire:click="sortBy('sku')">
                                SKU
                                <span>{!! $sortField === 'sku' ? ($sortDirection === 'asc' ? '&#8593;' : '&#8595;') : '&#8597;' !!}</span>
                            </button>
                        </th>
                        <th>
                            <button type="button" wire:click="sortBy('name')">
                                Item
                                <span>{!! $sortField === 'name' ? ($sortDirection === 'asc' ? '&#8593;' : '&#8595;') : '&#8597;' !!}</span>
                            </button>
                        </th>
                        <th>
                            <button type="button" wire:click="sortBy('location')">
                                Location
                                <span>{!! $sortField === 'location' ? ($sortDirection === 'asc' ? '&#8593;' : '&#8595;') : '&#8597;' !!}</span>
                            </button>
                        </th>
                        <th class="numeric">
                            <button type="button" wire:click="sortBy('quantity')">
                                Qty
                                <span>{!! $sortField === 'quantity' ? ($sortDirection === 'asc' ? '&#8593;' : '&#8595;') : '&#8597;' !!}</span>
                            </button>
                        </th>
                        <th class="numeric">Reorder</th>
                        <th>
                            <button type="button" wire:click="sortBy('status')">
                                Status
                                <span>{!! $sortField === 'status' ? ($sortDirection === 'asc' ? '&#8593;' : '&#8595;') : '&#8597;' !!}</span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr wire:key="inventory-item-{{ $item->id }}">
                            <td class="sku">{{ $item->sku }}</td>
                            <td>{{ $item->name }}</td>
                            <td>{{ $item->location }}</td>
                            <td class="numeric">{{ number_format($item->quantity) }}</td>
                            <td class="numeric">{{ number_format($item->reorder_level) }}</td>
                            <td>
                                <span class="{{ $this->statusClass($item->status) }}">
                                    {{ $this->statusLabel($item->status) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="empty-state" colspan="6">No inventory items match the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pagination-row">
            {{ $items->links() }}
        </div>
    </section>
</div>
