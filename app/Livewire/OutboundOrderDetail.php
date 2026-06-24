<?php

namespace App\Livewire;

use App\Models\OutboundOrder;
use App\Models\Tenant;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class OutboundOrderDetail extends Component
{
    public int $orderId = 0;

    public bool $editingRecipient = false;

    public bool $editingShipping = false;

    public string $recipientName = '';

    public string $recipientPhone = '';

    public string $recipientCountryCode = '';

    public string $recipientPostalCode = '';

    public string $recipientState = '';

    public string $recipientCity = '';

    public string $recipientAddressLine1 = '';

    public string $recipientAddressLine2 = '';

    public string $courier = '';

    public string $trackingNo = '';

    public string $note = '';

    private bool $visibleTenantIdsResolved = false;

    private array $visibleTenantIdsCache = [];

    public function mount(OutboundOrder $order): void
    {
        $this->orderId = $this->scopedOrderQuery()->findOrFail($order->id)->id;
    }

    public function editRecipient(): void
    {
        $order = $this->loadPendingOrder();
        $this->recipientName = (string) $order->recipient_name;
        $this->recipientPhone = (string) $order->recipient_phone;
        $this->recipientCountryCode = (string) $order->recipient_country_code;
        $this->recipientPostalCode = (string) $order->recipient_postal_code;
        $this->recipientState = (string) $order->recipient_state;
        $this->recipientCity = (string) $order->recipient_city;
        $this->recipientAddressLine1 = (string) $order->recipient_address_line1;
        $this->recipientAddressLine2 = (string) $order->recipient_address_line2;
        $this->editingRecipient = true;
    }

    public function cancelEditRecipient(): void
    {
        $this->editingRecipient = false;
    }

    public function saveRecipient(): void
    {
        $order = $this->loadPendingOrder();
        $this->recipientCountryCode = strtoupper(trim($this->recipientCountryCode));

        validator([
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'recipient_country_code' => $this->recipientCountryCode,
            'recipient_postal_code' => $this->recipientPostalCode,
            'recipient_state' => $this->recipientState,
            'recipient_city' => $this->recipientCity,
            'recipient_address_line1' => $this->recipientAddressLine1,
            'recipient_address_line2' => $this->recipientAddressLine2,
        ], [
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'recipient_country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'recipient_postal_code' => ['nullable', 'string', 'max:20'],
            'recipient_state' => ['nullable', 'string', 'max:100'],
            'recipient_city' => ['nullable', 'string', 'max:100'],
            'recipient_address_line1' => ['nullable', 'string', 'max:255'],
            'recipient_address_line2' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $data = [
            'recipient_name' => $this->nullableString($this->recipientName),
            'recipient_phone' => $this->nullableString($this->recipientPhone),
            'recipient_country_code' => $this->nullableString($this->recipientCountryCode),
            'recipient_postal_code' => $this->nullableString($this->recipientPostalCode),
            'recipient_state' => $this->nullableString($this->recipientState),
            'recipient_city' => $this->nullableString($this->recipientCity),
            'recipient_address_line1' => $this->nullableString($this->recipientAddressLine1),
            'recipient_address_line2' => $this->nullableString($this->recipientAddressLine2),
        ];

        $order->update($data);
        $order->fulfillmentGroup?->update($data);

        $this->editingRecipient = false;
        session()->flash('status', __('fulfillment_groups.recipient_updated'));
    }

    public function editShipping(): void
    {
        $order = $this->loadPendingOrder();
        $this->courier = (string) $order->courier;
        $this->trackingNo = (string) $order->tracking_no;
        $this->note = (string) $order->note;
        $this->editingShipping = true;
    }

    public function cancelEditShipping(): void
    {
        $this->editingShipping = false;
    }

    public function saveShipping(): void
    {
        $order = $this->loadPendingOrder();

        validator([
            'courier' => $this->courier,
            'tracking_no' => $this->trackingNo,
            'note' => $this->note,
        ], [
            'courier' => ['nullable', 'string', 'max:100'],
            'tracking_no' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $trackingNo = \App\Support\TrackingNumber::normalize($this->trackingNo);

        $order->update([
            'courier' => $this->nullableString($this->courier),
            'tracking_no' => $trackingNo,
            'note' => $this->nullableString($this->note),
        ]);
        $order->fulfillmentGroup?->update([
            'courier' => $this->nullableString($this->courier),
            'tracking_no' => $trackingNo,
            'note' => $this->nullableString($this->note),
        ]);

        $this->editingShipping = false;
        session()->flash('status', __('fulfillment_groups.shipping_updated'));
    }

    public function cancel(): void
    {
        $order = $this->scopedOrderQuery()
            ->with('leafLines')
            ->findOrFail($this->orderId);

        if ($order->status !== OutboundOrder::STATUS_PENDING) {
            return;
        }

        DB::transaction(function () use ($order): void {
            foreach ($order->leafLines as $line) {
                app(InventoryService::class)->releaseReserve(
                    tenantId: $order->tenant_id,
                    warehouseId: $order->warehouse_id,
                    stockItemId: $line->stock_item_id,
                    quantity: $line->qty,
                    context: [
                        'ref_type' => 'outbound_order',
                        'ref_id' => (string) $order->id,
                        'user_id' => Auth::id(),
                    ],
                );
            }

            $order->status = OutboundOrder::STATUS_CANCELLED;
            $order->cancelled_at = now();
            $order->cancelled_by_user_id = Auth::id();
            $order->save();
        });

        session()->flash('status', __('outbound.order_cancelled'));
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            OutboundOrder::STATUS_PENDING => __('outbound.status_pending'),
            OutboundOrder::STATUS_SHIPPED => __('outbound.status_shipped'),
            OutboundOrder::STATUS_CANCELLED => __('outbound.status_cancelled'),
            default => $status,
        };
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            OutboundOrder::STATUS_PENDING => 'amber',
            OutboundOrder::STATUS_SHIPPED => 'green',
            OutboundOrder::STATUS_CANCELLED => 'red',
            default => 'zinc',
        };
    }

    public function render()
    {
        $order = $this->scopedOrderQuery()
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'createdBy:id,name',
                'shippedBy:id,name',
                'cancelledBy:id,name',
                'parentLines.sku:id,sku,sku_type,name',
                'parentLines.stockItem:id,code,name',
                'parentLines.childLines.stockItem:id,code,name',
                'salesOrders:id,platform_order_id,recipient_name,recipient_city,fulfillment_status',
                'salesOrders.lines:id,sales_order_id',
                'fulfillmentGroup:id,reference_no,status',
                'fulfillmentGroup.packScans' => fn ($query) => $query
                    ->with(['sku:id,sku,name', 'stockItem:id,code,name,short_name', 'scannedBy:id,name'])
                    ->limit(10),
            ])
            ->findOrFail($this->orderId);

        return view('livewire.outbound-order-detail', [
            'order' => $order,
        ])->layout('inventory', [
            'title' => __('outbound.detail_page_title'),
            'subtitle' => __('outbound.detail_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function loadPendingOrder(): OutboundOrder
    {
        $order = $this->scopedOrderQuery()->with('fulfillmentGroup')->findOrFail($this->orderId);

        if ($order->status !== OutboundOrder::STATUS_PENDING) {
            abort(403);
        }

        return $order;
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function scopedOrderQuery()
    {
        return OutboundOrder::query()->whereIn('tenant_id', $this->visibleTenantIds());
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function visibleTenantIds(): array
    {
        if ($this->visibleTenantIdsResolved) {
            return $this->visibleTenantIdsCache;
        }

        $this->visibleTenantIdsResolved = true;

        if ($this->isInternalUser()) {
            return $this->visibleTenantIdsCache = Tenant::query()->pluck('id')->all();
        }

        $user = Auth::user();

        if (! $user) {
            return $this->visibleTenantIdsCache = [];
        }

        return $this->visibleTenantIdsCache = $user->activeTenantIds();
    }
}
