<?php

namespace App\Livewire;

use App\Models\Issue;
use App\Models\IssueLine;
use App\Models\FulfillmentGroup;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class IssueCreate extends Component
{
    public string $tenantId = '';

    public string $salesOrderId = '';

    public string $salesOrderSearch = '';

    public string $outboundOrderId = '';

    public string $outboundOrderSearch = '';

    public string $fulfillmentGroupId = '';

    public string $issueType = Issue::TYPE_MISSING;

    public string $status = Issue::STATUS_OPEN;

    public string $reportedAt = '';

    public string $reportedBy = '';

    public string $note = '';

    public array $salesOrderLines = [];

    public array $manualLines = [
        ['sku_id' => '', 'stock_item_id' => '', 'qty' => 1, 'condition' => IssueLine::CONDITION_UNKNOWN, 'action' => IssueLine::ACTION_INVESTIGATE, 'note' => ''],
    ];

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(?SalesOrder $order = null, ?FulfillmentGroup $group = null): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }

        if ($group) {
            $this->applyFulfillmentGroupContext($group);
            $this->applyLineContextFromRequest();

            return;
        }

        if ($order) {
            if (! in_array($order->tenant_id, $this->allowedTenantIds(), true)) {
                abort(403);
            }

            $this->tenantId = (string) $order->tenant_id;
            $this->salesOrderId = (string) $order->id;
            $this->loadSalesOrderLines();

            return;
        }

        if (! $this->isInternalUser() && count($this->allowedTenantIds()) === 1) {
            $this->tenantId = (string) $this->allowedTenantIds()[0];
        }
    }

    public function updatedTenantId(): void
    {
        $this->salesOrderId = '';
        $this->salesOrderSearch = '';
        $this->outboundOrderId = '';
        $this->outboundOrderSearch = '';
        $this->fulfillmentGroupId = '';
        $this->salesOrderLines = [];
    }

    public function updatedSalesOrderId(): void
    {
        $this->loadSalesOrderLines();
    }

    public function selectSalesOrder(int $orderId): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($orderId);

        $this->tenantId = (string) $order->tenant_id;
        $this->salesOrderId = (string) $order->id;
        $this->salesOrderSearch = '';
        $this->loadSalesOrderLines();
    }

    public function clearSalesOrder(): void
    {
        $this->salesOrderId = '';
        $this->salesOrderSearch = '';
        $this->salesOrderLines = [];
    }

    public function selectOutboundOrder(int $orderId): void
    {
        $order = OutboundOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($orderId);

        $this->tenantId = (string) $order->tenant_id;
        $this->outboundOrderId = (string) $order->id;
        $this->outboundOrderSearch = '';
    }

    public function clearOutboundOrder(): void
    {
        $this->outboundOrderId = '';
        $this->outboundOrderSearch = '';
    }

    public function addManualLine(): void
    {
        $this->manualLines[] = ['sku_id' => '', 'stock_item_id' => '', 'qty' => 1, 'condition' => IssueLine::CONDITION_UNKNOWN, 'action' => IssueLine::ACTION_INVESTIGATE, 'note' => ''];
    }

    public function removeManualLine(int $index): void
    {
        unset($this->manualLines[$index]);
        $this->manualLines = array_values($this->manualLines);
    }

    public function save(): mixed
    {
        $tenantId = $this->validatedTenantId();
        $fulfillmentGroup = $this->validatedFulfillmentGroup($tenantId);
        $salesOrder = $this->validatedSalesOrder($tenantId);
        $outboundOrder = $this->validatedOutboundOrder($tenantId);

        validator([
            'issue_type' => $this->issueType,
            'status' => $this->status,
            'reported_at' => $this->reportedAt,
            'reported_by' => $this->reportedBy,
            'note' => $this->note,
        ], [
            'issue_type' => ['required', Rule::in(array_keys(Issue::typeOptions()))],
            'status' => ['required', Rule::in(array_keys(Issue::statusOptions()))],
            'reported_at' => ['nullable', 'date'],
            'reported_by' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $linePayloads = $this->validatedLinePayloads($tenantId, $salesOrder);

        if (($salesOrder || ($outboundOrder && ! $fulfillmentGroup)) && $linePayloads === []) {
            throw ValidationException::withMessages(['lines' => __('issues.validation_lines_required')]);
        }

        if (! $salesOrder && ! $outboundOrder && $linePayloads === [] && $this->nullableString($this->note) === null) {
            throw ValidationException::withMessages(['unknownIssue' => __('issues.validation_unknown_issue_requires_note_or_line')]);
        }

        $case = DB::transaction(function () use ($tenantId, $fulfillmentGroup, $salesOrder, $outboundOrder, $linePayloads): Issue {
            $case = Issue::create([
                'tenant_id' => $tenantId,
                'sales_order_id' => $salesOrder?->id,
                'fulfillment_group_id' => $fulfillmentGroup?->id,
                'outbound_order_id' => $outboundOrder?->id,
                'issue_no' => 'ISS-PENDING-'.Str::uuid(),
                'issue_type' => $this->issueType,
                'status' => $this->status,
                'reported_at' => $this->reportedAt !== '' ? $this->reportedAt : null,
                'reported_by' => $this->nullableString($this->reportedBy),
                'note' => $this->nullableString($this->note),
                'created_by_user_id' => Auth::id(),
                'updated_by_user_id' => Auth::id(),
            ]);

            foreach ($linePayloads as $linePayload) {
                $case->lines()->create($linePayload);
            }

            $case->update(['issue_no' => Issue::buildIssueNo($case->id)]);

            return $case;
        });

        session()->flash('status', __('issues.issue_created'));

        return redirect()->route('issues.show', $case);
    }

    public function render()
    {
        $tenantId = $this->tenantId !== '' ? (int) $this->tenantId : null;

        return view('livewire.issue-create', [
            'tenants' => Tenant::query()
                ->whereIn('id', $this->allowedTenantIds())
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'selectedSalesOrder' => $this->selectedSalesOrder(),
            'selectedOutboundOrder' => $this->selectedOutboundOrder(),
            'selectedFulfillmentGroup' => $this->selectedFulfillmentGroup(),
            'salesOrderResults' => $this->salesOrderResults($tenantId),
            'outboundOrderResults' => $this->outboundOrderResults($tenantId),
            'skuOptions' => $this->skuOptions($tenantId),
            'types' => Issue::typeOptions(),
            'statuses' => Issue::statusOptions(),
            'conditions' => IssueLine::conditionOptions(),
            'actions' => IssueLine::actionOptions(),
            'showTenantSelect' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('issues.create_page_title'),
            'subtitle' => __('issues.create_page_subtitle'),
        ]);
    }

    private function loadSalesOrderLines(): void
    {
        $this->salesOrderLines = [];

        if ($this->salesOrderId === '') {
            return;
        }

        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with('lines.sku.stockItem')
            ->findOrFail((int) $this->salesOrderId);

        $this->tenantId = (string) $order->tenant_id;

        $this->salesOrderLines = $order->lines
            ->map(fn (SalesOrderLine $line) => [
                'selected' => false,
                'sales_order_line_id' => $line->id,
                'label' => $line->sku?->sku.' / '.$line->sku?->name,
                'stock_item' => $line->sku?->stockItem?->code,
                'max_qty' => $line->quantity,
                'qty' => 1,
                'condition' => IssueLine::CONDITION_UNKNOWN,
                'action' => IssueLine::ACTION_INVESTIGATE,
                'note' => '',
            ])
            ->all();
    }

    private function validatedTenantId(): int
    {
        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenantId' => __('issues.validation_invalid_tenant')]);
        }

        return $tenantId;
    }

    private function validatedSalesOrder(int $tenantId): ?SalesOrder
    {
        if ($this->salesOrderId === '') {
            return null;
        }

        return SalesOrder::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail((int) $this->salesOrderId);
    }

    private function validatedFulfillmentGroup(int $tenantId): ?FulfillmentGroup
    {
        if ($this->fulfillmentGroupId === '') {
            return null;
        }

        return FulfillmentGroup::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail((int) $this->fulfillmentGroupId);
    }

    private function validatedOutboundOrder(int $tenantId): ?OutboundOrder
    {
        if ($this->outboundOrderId === '') {
            return null;
        }

        return OutboundOrder::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail((int) $this->outboundOrderId);
    }

    private function validatedLinePayloads(int $tenantId, ?SalesOrder $salesOrder): array
    {
        $payloads = [];
        $conditionValues = array_keys(IssueLine::conditionOptions());
        $actionValues = array_keys(IssueLine::actionOptions());

        foreach ($this->salesOrderLines as $line) {
            if (! ($line['selected'] ?? false)) {
                continue;
            }

            $sourceLine = SalesOrderLine::query()
                ->whereHas('salesOrder', fn ($query) => $query->where('tenant_id', $tenantId))
                ->with('sku')
                ->findOrFail((int) $line['sales_order_line_id']);
            $qty = (int) ($line['qty'] ?? 0);

            if ($qty < 1 || $qty > $sourceLine->quantity) {
                throw ValidationException::withMessages(['lines' => __('issues.validation_qty_exceeds_source')]);
            }

            if ($salesOrder && $sourceLine->sales_order_id !== $salesOrder->id) {
                throw ValidationException::withMessages(['lines' => __('issues.validation_invalid_source_line')]);
            }

            if (! in_array($line['condition'] ?? '', $conditionValues, true) || ! in_array($line['action'] ?? '', $actionValues, true)) {
                throw ValidationException::withMessages(['lines' => __('issues.validation_invalid_line_values')]);
            }

            $payloads[] = [
                'tenant_id' => $tenantId,
                'sales_order_line_id' => $sourceLine->id,
                'sku_id' => $sourceLine->sku_id,
                'stock_item_id' => $sourceLine->sku?->stock_item_id,
                'qty' => $qty,
                'condition' => $line['condition'] ?? IssueLine::CONDITION_UNKNOWN,
                'action' => $line['action'] ?? IssueLine::ACTION_INVESTIGATE,
                'note' => $this->nullableString($line['note'] ?? ''),
            ];
        }

        foreach ($this->manualLines as $line) {
            if (($line['sku_id'] ?? '') === '' && ($line['stock_item_id'] ?? '') === '') {
                continue;
            }

            $sku = ($line['sku_id'] ?? '') !== ''
                ? Sku::query()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail((int) $line['sku_id'])
                : null;
            $stockItem = ($line['stock_item_id'] ?? '') !== ''
                ? StockItem::query()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail((int) $line['stock_item_id'])
                : null;
            $qty = (int) ($line['qty'] ?? 0);

            if ($qty < 1) {
                throw ValidationException::withMessages(['manualLines' => __('issues.validation_qty_min')]);
            }

            if (! in_array($line['condition'] ?? '', $conditionValues, true) || ! in_array($line['action'] ?? '', $actionValues, true)) {
                throw ValidationException::withMessages(['manualLines' => __('issues.validation_invalid_line_values')]);
            }

            $payloads[] = [
                'tenant_id' => $tenantId,
                'sku_id' => $sku?->id,
                'stock_item_id' => $sku?->stock_item_id ?? $stockItem?->id,
                'qty' => $qty,
                'condition' => $line['condition'] ?? IssueLine::CONDITION_UNKNOWN,
                'action' => $line['action'] ?? IssueLine::ACTION_INVESTIGATE,
                'note' => $this->nullableString($line['note'] ?? ''),
            ];
        }

        return $payloads;
    }

    private function selectedSalesOrder(): ?SalesOrder
    {
        if ($this->salesOrderId === '') {
            return null;
        }

        return SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->find((int) $this->salesOrderId);
    }

    private function selectedOutboundOrder(): ?OutboundOrder
    {
        if ($this->outboundOrderId === '') {
            return null;
        }

        return OutboundOrder::query()
            ->with(['warehouse:id,code,name', 'fulfillmentGroup.orders:id,platform_order_id'])
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->find((int) $this->outboundOrderId);
    }

    private function selectedFulfillmentGroup(): ?FulfillmentGroup
    {
        if ($this->fulfillmentGroupId === '') {
            return null;
        }

        return FulfillmentGroup::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->find((int) $this->fulfillmentGroupId);
    }

    private function applyFulfillmentGroupContext(FulfillmentGroup $group): void
    {
        if (! in_array($group->tenant_id, $this->allowedTenantIds(), true)) {
            abort(403);
        }

        $group->loadMissing('outboundOrder:id,tenant_id,fulfillment_group_id');

        $this->tenantId = (string) $group->tenant_id;
        $this->fulfillmentGroupId = (string) $group->id;
        $this->outboundOrderId = $group->outboundOrder ? (string) $group->outboundOrder->id : '';
        $this->note = __('issues.fulfillment_group_note', ['reference' => $group->reference_no]);
    }

    private function applyLineContextFromRequest(): void
    {
        $skuId = (string) request()->query('sku_id', '');
        $stockItemId = (string) request()->query('stock_item_id', '');

        if ($skuId === '' && $stockItemId === '') {
            return;
        }

        $qty = max(1, (int) request()->query('qty', 1));

        $this->manualLines = [[
            'sku_id' => $skuId,
            'stock_item_id' => $stockItemId,
            'qty' => $qty,
            'condition' => IssueLine::CONDITION_UNKNOWN,
            'action' => IssueLine::ACTION_INVESTIGATE,
            'note' => '',
        ]];
    }

    private function salesOrderResults(?int $tenantId)
    {
        $term = trim($this->salesOrderSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.$term.'%';

        return SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where(fn ($query) => $query
                ->where('platform_order_id', 'like', $like)
                ->orWhere('tracking_no', 'like', $like)
                ->orWhere('recipient_name', 'like', $like)
                ->orWhere('recipient_phone', 'like', $like))
            ->latest()
            ->limit(20)
            ->get(['id', 'tenant_id', 'platform_order_id', 'tracking_no', 'recipient_name', 'recipient_phone']);
    }

    private function outboundOrderResults(?int $tenantId)
    {
        $term = trim($this->outboundOrderSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.$term.'%';

        return OutboundOrder::query()
            ->with(['warehouse:id,code,name', 'fulfillmentGroup.orders:id,platform_order_id'])
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where(fn ($query) => $query
                ->where('ref', 'like', $like)
                ->orWhere('tracking_no', 'like', $like)
                ->orWhere('recipient_name', 'like', $like)
                ->orWhere('recipient_phone', 'like', $like)
                ->orWhereHas('fulfillmentGroup', fn ($query) => $query
                    ->where('reference_no', 'like', $like)
                    ->orWhere('tracking_no', 'like', $like)
                    ->orWhere('recipient_name', 'like', $like)
                    ->orWhere('recipient_phone', 'like', $like))
                ->orWhereHas('fulfillmentGroup.orders', fn ($query) => $query
                    ->where('sales_orders.platform_order_id', 'like', $like)
                    ->orWhere('sales_orders.tracking_no', 'like', $like)
                    ->orWhere('sales_orders.recipient_name', 'like', $like)
                    ->orWhere('sales_orders.recipient_phone', 'like', $like)))
            ->latest()
            ->limit(20)
            ->get(['id', 'tenant_id', 'warehouse_id', 'fulfillment_group_id', 'ref', 'status', 'tracking_no', 'recipient_name']);
    }

    private function skuOptions(?int $tenantId)
    {
        return Sku::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->with('stockItem:id,code,name')
            ->orderBy('sku')
            ->limit(200)
            ->get(['id', 'tenant_id', 'stock_item_id', 'sku', 'name']);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
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

        $user = Auth::user();

        if (! $user) {
            return $this->allowedTenantIdsCache = [];
        }

        return $this->allowedTenantIdsCache = $user->activeTenantIds();
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
