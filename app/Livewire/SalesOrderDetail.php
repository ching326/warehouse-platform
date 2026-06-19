<?php

namespace App\Livewire;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SalesOrderDetail extends Component
{
    public int $orderId = 0;

    public bool $editingRecipient = false;

    public string $editRecipientName = '';

    public string $editRecipientPhone = '';

    public string $editRecipientCountryCode = '';

    public string $editRecipientPostalCode = '';

    public string $editRecipientState = '';

    public string $editRecipientCity = '';

    public string $editRecipientAddressLine1 = '';

    public string $editRecipientAddressLine2 = '';

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(SalesOrder $order): void
    {
        if (! in_array($order->tenant_id, $this->allowedTenantIds(), true)) {
            abort(403);
        }

        $this->orderId = $order->id;
    }

    public function editRecipient(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        $this->editRecipientName = (string) $order->recipient_name;
        $this->editRecipientPhone = (string) $order->recipient_phone;
        $this->editRecipientCountryCode = (string) $order->recipient_country_code;
        $this->editRecipientPostalCode = (string) $order->recipient_postal_code;
        $this->editRecipientState = (string) $order->recipient_state;
        $this->editRecipientCity = (string) $order->recipient_city;
        $this->editRecipientAddressLine1 = (string) $order->recipient_address_line1;
        $this->editRecipientAddressLine2 = (string) $order->recipient_address_line2;
        $this->editingRecipient = true;
    }

    public function cancelEditRecipient(): void
    {
        $this->editingRecipient = false;
    }

    public function saveRecipient(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        $this->editRecipientCountryCode = strtoupper(trim($this->editRecipientCountryCode));

        validator([
            'recipient_name' => $this->editRecipientName,
            'recipient_phone' => $this->editRecipientPhone,
            'recipient_country_code' => $this->editRecipientCountryCode,
            'recipient_postal_code' => $this->editRecipientPostalCode,
            'recipient_state' => $this->editRecipientState,
            'recipient_city' => $this->editRecipientCity,
            'recipient_address_line1' => $this->editRecipientAddressLine1,
            'recipient_address_line2' => $this->editRecipientAddressLine2,
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

        $order->update([
            'recipient_name' => $this->nullableString($this->editRecipientName),
            'recipient_phone' => $this->nullableString($this->editRecipientPhone),
            'recipient_country_code' => $this->nullableString($this->editRecipientCountryCode),
            'recipient_postal_code' => $this->nullableString($this->editRecipientPostalCode),
            'recipient_state' => $this->nullableString($this->editRecipientState),
            'recipient_city' => $this->nullableString($this->editRecipientCity),
            'recipient_address_line1' => $this->nullableString($this->editRecipientAddressLine1),
            'recipient_address_line2' => $this->nullableString($this->editRecipientAddressLine2),
        ]);

        $this->editingRecipient = false;
        session()->flash('status', __('sales_orders.recipient_updated'));
    }

    public function cancelOrder(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        if ($order->order_status === SalesOrder::ORDER_STATUS_CANCELLED) {
            return;
        }

        if (! in_array($order->fulfillment_status, [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            SalesOrder::FULFILLMENT_STATUS_READY,
        ], true)) {
            session()->flash('error', __('sales_orders.cannot_cancel_in_group'));

            return;
        }

        $order->update([
            'order_status' => SalesOrder::ORDER_STATUS_CANCELLED,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_CANCELLED,
        ]);

        $order->lines()->update(['line_status' => SalesOrderLine::STATUS_CANCELLED]);
        session()->flash('status', __('sales_orders.order_cancelled_msg'));
    }

    public function fulfillmentStatusLabel(string $status): string
    {
        return [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED => __('sales_orders.fulfillment_unfulfilled'),
            SalesOrder::FULFILLMENT_STATUS_READY => __('sales_orders.fulfillment_ready'),
            SalesOrder::FULFILLMENT_STATUS_IN_GROUP => __('sales_orders.fulfillment_in_group'),
            SalesOrder::FULFILLMENT_STATUS_SHIPPED => __('sales_orders.fulfillment_shipped'),
            SalesOrder::FULFILLMENT_STATUS_CANCELLED => __('sales_orders.fulfillment_cancelled'),
        ][$status] ?? $status;
    }

    public function orderStatusLabel(string $status): string
    {
        return [
            SalesOrder::ORDER_STATUS_PENDING => __('sales_orders.order_pending'),
            SalesOrder::ORDER_STATUS_CANCELLED => __('sales_orders.order_cancelled'),
        ][$status] ?? $status;
    }

    public function fulfillmentStatusColor(string $status): string
    {
        return match ($status) {
            SalesOrder::FULFILLMENT_STATUS_READY => 'blue',
            SalesOrder::FULFILLMENT_STATUS_IN_GROUP => 'amber',
            SalesOrder::FULFILLMENT_STATUS_SHIPPED => 'green',
            SalesOrder::FULFILLMENT_STATUS_CANCELLED => 'red',
            default => 'zinc',
        };
    }

    public function orderStatusColor(string $status): string
    {
        return $status === SalesOrder::ORDER_STATUS_CANCELLED ? 'red' : 'zinc';
    }

    public function lineStatusLabel(string $status): string
    {
        return $status === SalesOrderLine::STATUS_CANCELLED
            ? __('sales_orders.line_cancelled')
            : __('sales_orders.line_ready');
    }

    public function render()
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with(['shop.tenant', 'lines.sku.stockItem', 'createdBy'])
            ->findOrFail($this->orderId);

        $relatedOrders = collect();

        if ($order->ship_together_key) {
            $relatedOrders = SalesOrder::query()
                ->whereIn('tenant_id', $this->allowedTenantIds())
                ->where('ship_together_key', $order->ship_together_key)
                ->where('id', '!=', $order->id)
                ->whereIn('fulfillment_status', [
                    SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                    SalesOrder::FULFILLMENT_STATUS_READY,
                ])
                ->with('shop:id,name,platform')
                ->orderBy('created_at')
                ->get();
        }

        $activities = $order->activities()->with('causer')->latest()->get();

        return view('livewire.sales-order-detail', [
            'order' => $order,
            'relatedOrders' => $relatedOrders,
            'activities' => $activities,
        ])->layout('inventory', [
            'title' => __('sales_orders.detail_page_title'),
            'subtitle' => $order->platform_order_id ?? "#{$order->id}",
        ]);
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        if ($this->isInternalUser()) {
            return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
        }

        return $this->allowedTenantIdsCache = Auth::user()
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
