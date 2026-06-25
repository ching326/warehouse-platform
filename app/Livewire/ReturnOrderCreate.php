<?php

namespace App\Livewire;

use App\Models\Issue;
use App\Models\ReturnOrder;
use App\Models\SalesOrder;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Support\TrackingNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class ReturnOrderCreate extends Component
{
    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    public string $warehouseId = '';

    public string $return_type = ReturnOrder::TYPE_CUSTOMER_RETURN;

    public string $return_reason = '';

    public string $reason_note = '';

    public string $issueId = '';

    public string $salesOrderId = '';

    public string $original_order_no = '';

    public string $external_return_id = '';

    public string $customer_name = '';

    public string $sender_name = '';

    public string $sender_phone = '';

    public string $shipping_method = '';

    public string $tracking_no = '';

    public string $payment_type = ReturnOrder::PAYMENT_UNKNOWN;

    public string $expected_arrival_date = '';

    public string $package_count = '';

    public string $note = '';

    public array $lines = [['sku_id' => '', 'expected_qty' => '1', 'note' => '']];

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            $this->tenantId = (string) ($this->allowedTenantIds()[0] ?? '');
        }
    }

    public function updatedTenantId(): void
    {
        $this->issueId = $this->salesOrderId = '';
        $this->lines = [['sku_id' => '', 'expected_qty' => '1', 'note' => '']];
    }

    public function addLine(): void
    {
        $this->lines[] = ['sku_id' => '', 'expected_qty' => '1', 'note' => ''];
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) > 1) {
            array_splice($this->lines, $index, 1);
            $this->lines = array_values($this->lines);
        }
    }

    public function save()
    {
        $tenantId = $this->validatedTenantId();
        validator($this->payload($tenantId), [
            'tenant_id' => ['required', 'integer'], 'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'return_type' => ['required', Rule::in(array_keys(ReturnOrder::typeOptions()))], 'return_reason' => ['nullable', Rule::in(array_keys(ReturnOrder::reasonOptions()))],
            'payment_type' => ['required', Rule::in(array_keys(ReturnOrder::paymentTypeOptions()))], 'expected_arrival_date' => ['nullable', 'date'],
            'issue_id' => ['nullable', 'integer', Rule::exists('issues', 'id')->where('tenant_id', $tenantId)], 'sales_order_id' => ['nullable', 'integer', Rule::exists('sales_orders', 'id')->where('tenant_id', $tenantId)],
            'tracking_no' => ['nullable', 'string', 'max:255'], 'original_order_no' => ['nullable', 'string', 'max:255'], 'external_return_id' => ['nullable', 'string', 'max:255'], 'customer_name' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.sku_id' => ['required', 'integer', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)], 'lines.*.expected_qty' => ['required', 'integer', 'min:1'],
        ])->validate();

        DB::transaction(function () use ($tenantId): void {
            $order = ReturnOrder::create([
                'tenant_id' => $tenantId, 'warehouse_id' => $this->intOrNull($this->warehouseId), 'issue_id' => $this->intOrNull($this->issueId), 'sales_order_id' => $this->intOrNull($this->salesOrderId),
                'return_no' => 'RTN-PENDING-'.Str::uuid(), 'status' => ReturnOrder::STATUS_ANNOUNCED, 'return_type' => $this->return_type, 'return_reason' => $this->nullable($this->return_reason),
                'reason_note' => $this->nullable($this->reason_note), 'external_return_id' => $this->nullable($this->external_return_id), 'original_order_no' => $this->nullable($this->original_order_no), 'customer_name' => $this->nullable($this->customer_name),
                'sender_name' => $this->nullable($this->sender_name), 'sender_phone' => $this->nullable($this->sender_phone), 'shipping_method' => $this->nullable($this->shipping_method), 'tracking_no' => TrackingNumber::normalize($this->tracking_no),
                'payment_type' => $this->payment_type, 'expected_arrival_date' => $this->nullable($this->expected_arrival_date), 'package_count' => $this->intOrNull($this->package_count), 'note' => $this->nullable($this->note), 'created_by_user_id' => Auth::id(),
            ]);
            $order->update(['return_no' => ReturnOrder::buildReturnNo($order->id, $order->tenant->code)]);
            foreach ($this->lines as $line) {
                $sku = Sku::query()->where('tenant_id', $tenantId)->findOrFail($line['sku_id']);
                $order->lines()->create(['tenant_id' => $tenantId, 'sku_id' => $sku->id, 'stock_item_id' => $sku->stock_item_id, 'expected_qty' => (int) $line['expected_qty'], 'received_qty' => 0, 'note' => $this->nullable($line['note'] ?? '')]);
            }
        });
        session()->flash('status', __('return_orders.created'));

        return redirect()->route('return-orders.index');
    }

    public function render()
    {
        return view('livewire.return-order-create', ['tenants' => Tenant::query()->whereIn('id', $this->allowedTenantIds())->orderBy('name')->get(['id', 'code', 'name']), 'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name']), 'issues' => Issue::query()->where('tenant_id', $this->tenantId)->latest('id')->get(['id', 'issue_no']), 'salesOrders' => SalesOrder::query()->where('tenant_id', $this->tenantId)->latest('id')->get(['id', 'platform_order_id']), 'skus' => Sku::query()->where('tenant_id', $this->tenantId)->whereNotNull('stock_item_id')->orderBy('sku')->get(['id', 'sku', 'name', 'stock_item_id']), 'types' => ReturnOrder::typeOptions(), 'reasons' => ReturnOrder::reasonOptions(), 'paymentTypes' => ReturnOrder::paymentTypeOptions(), 'showTenantSelect' => $this->isInternalUser()])->layout('inventory', ['title' => __('return_orders.create_page_title'), 'subtitle' => __('return_orders.create_page_subtitle')]);
    }

    private function payload(int $tenantId): array
    {
        return ['tenant_id' => $tenantId, 'warehouse_id' => $this->warehouseId, 'return_type' => $this->return_type, 'return_reason' => $this->return_reason, 'payment_type' => $this->payment_type, 'expected_arrival_date' => $this->expected_arrival_date, 'issue_id' => $this->issueId, 'sales_order_id' => $this->salesOrderId, 'tracking_no' => $this->tracking_no, 'original_order_no' => $this->original_order_no, 'external_return_id' => $this->external_return_id, 'customer_name' => $this->customer_name, 'lines' => $this->lines];
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        return $this->isInternalUser() ? Tenant::query()->pluck('id')->all() : (Auth::user()?->activeTenantIds() ?? []);
    }

    private function validatedTenantId(): int
    {
        $id = (int) $this->tenantId;
        if ($id <= 0 || ! in_array($id, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenantId' => __('return_orders.invalid_tenant')]);
        }

        return $id;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(?string $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : (int) $value;
    }
}
