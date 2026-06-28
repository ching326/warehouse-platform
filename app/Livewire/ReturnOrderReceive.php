<?php

namespace App\Livewire;

use App\Models\ReturnOrder;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ReturnOrderReceive extends Component
{
    public int $returnOrderId = 0;

    public string $collect_amount = '';

    public array $lineDrafts = [];

    public array $newLines = [];

    public array $newLineSkuSearches = [];

    public function mount(ReturnOrder $returnOrder): void
    {
        $this->staffOnly();
        $this->returnOrderId = $returnOrder->id;
        $this->fillDrafts($returnOrder->load('lines'));
    }

    public function addUnexpectedLine(): void
    {
        $this->newLines[] = [
            'sku_id' => '',
            'received_qty' => '1',
            'received_location_id' => '',
            'note' => '',
        ];
        $this->newLineSkuSearches[] = '';
    }

    public function updatedNewLineSkuSearches(mixed $_value, mixed $key): void
    {
        $index = (int) $key;

        if (isset($this->newLines[$index])) {
            $this->newLines[$index]['sku_id'] = '';
        }
    }

    public function saveReceive()
    {
        $this->staffOnly();

        $order = $this->orderQuery()->with('lines')->findOrFail($this->returnOrderId);
        $validated = $this->validateReceive($order);

        foreach ($order->lines as $line) {
            $draft = $validated['lineDrafts'][$line->id] ?? [];

            $line->update([
                'received_qty' => (int) ($draft['received_qty'] ?? 0),
                'received_location_id' => $this->intOrNull($draft['received_location_id'] ?? ''),
                'received_at' => now(),
            ]);
        }

        foreach ($validated['newLines'] as $line) {
            $sku = Sku::query()
                ->where('tenant_id', $order->tenant_id)
                ->findOrFail($line['sku_id']);

            $order->lines()->create([
                'tenant_id' => $order->tenant_id,
                'sku_id' => $sku->id,
                'stock_item_id' => $sku->stock_item_id,
                'expected_qty' => 0,
                'received_qty' => (int) $line['received_qty'],
                'received_location_id' => $this->intOrNull($line['received_location_id'] ?? ''),
                'note' => $line['note'] ?? null,
                'received_at' => now(),
            ]);
        }

        $order->update([
            'collect_amount' => $this->collect_amount === '' ? null : $this->collect_amount,
            'received_at' => now(),
            'received_by_user_id' => Auth::id(),
            'status' => ReturnOrder::STATUS_RECEIVED,
        ]);

        session()->flash('status', __('return_orders.received'));

        return redirect()->route('return-orders.show', $order);
    }

    public function render()
    {
        $order = $this->orderQuery()->with('lines')->findOrFail($this->returnOrderId);

        return view('livewire.return-order-receive', [
            'order' => $order,
            'skuOptionsByLine' => $this->skuOptionsByLine($order),
            'locations' => WarehouseLocation::query()
                ->where('warehouse_id', $order->warehouse_id)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ])->layout('inventory', [
            'title' => __('return_orders.receive_page_title'),
            'subtitle' => $order->return_no,
        ]);
    }

    private function validateReceive(ReturnOrder $order): array
    {
        $newLines = array_values(array_filter(
            $this->newLines,
            fn (array $line): bool => trim((string) ($line['sku_id'] ?? '')) !== ''
        ));

        return validator([
            'collect_amount' => $this->collect_amount,
            'lineDrafts' => $this->lineDrafts,
            'newLines' => $newLines,
        ], [
            'collect_amount' => ['nullable', 'numeric', 'min:0'],
            'lineDrafts' => ['array'],
            'lineDrafts.*.received_qty' => ['required', 'integer', 'min:0'],
            'lineDrafts.*.received_location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $order->warehouse_id),
            ],
            'newLines' => ['array'],
            'newLines.*.sku_id' => [
                'required',
                'integer',
                Rule::exists('skus', 'id')->where('tenant_id', $order->tenant_id),
            ],
            'newLines.*.received_qty' => ['required', 'integer', 'min:1'],
            'newLines.*.received_location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $order->warehouse_id),
            ],
            'newLines.*.note' => ['nullable', 'string'],
        ])->validate();
    }

    private function skuOptionsByLine(ReturnOrder $order): array
    {
        return collect($this->newLines)
            ->keys()
            ->mapWithKeys(fn ($index) => [$index => $this->skuOptions($order, (int) $index)])
            ->all();
    }

    private function skuOptions(ReturnOrder $order, int $lineIndex)
    {
        $searchTerm = trim((string) ($this->newLineSkuSearches[$lineIndex] ?? ''));
        $search = '%'.$searchTerm.'%';

        return Sku::query()
            ->where('tenant_id', $order->tenant_id)
            ->whereNotNull('stock_item_id')
            ->when($searchTerm !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('sku', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('name_en', 'like', $search)
                        ->orWhere('name_ja', 'like', $search)
                        ->orWhere('name_zh_tw', 'like', $search)
                        ->orWhere('name_zh_cn', 'like', $search)
                        ->orWhere('platform_sku', 'like', $search)
                        ->orWhere('platform_label_code', 'like', $search)
                        ->orWhereHas('stockItem', function ($query) use ($search): void {
                            $query
                                ->where('code', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhere('barcode', 'like', $search);
                        });
                });
            })
            ->with('stockItem:id,code,name')
            ->orderBy('sku')
            ->limit(50)
            ->get(['id', 'tenant_id', 'stock_item_id', 'sku', 'name', 'platform_sku', 'platform_label_code']);
    }

    private function fillDrafts(ReturnOrder $order): void
    {
        $this->collect_amount = (string) $order->collect_amount;

        foreach ($order->lines as $line) {
            $this->lineDrafts[$line->id] = [
                'received_qty' => (string) ($line->received_qty ?: $line->expected_qty),
                'received_location_id' => (string) $line->received_location_id,
            ];
        }
    }

    private function orderQuery()
    {
        return ReturnOrder::query()->whereIn('tenant_id', $this->allowedTenantIds());
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function staffOnly(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    private function allowedTenantIds(): array
    {
        return $this->isInternalUser()
            ? Tenant::query()->pluck('id')->all()
            : (Auth::user()?->activeTenantIds() ?? []);
    }

    private function intOrNull($value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : (int) $value;
    }
}
