<?php

namespace App\Livewire;

use App\Models\ExceptionCase;
use App\Models\ExceptionCaseLine;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Sku;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ExceptionCaseCreate extends Component
{
    public string $tenantId = '';

    public string $salesOrderId = '';

    public string $outboundOrderId = '';

    public string $caseType = ExceptionCase::TYPE_MISSING;

    public string $status = ExceptionCase::STATUS_OPEN;

    public string $reportedAt = '';

    public string $reportedBy = '';

    public string $note = '';

    public array $salesOrderLines = [];

    public array $manualLines = [
        ['sku_id' => '', 'qty' => 1, 'condition' => ExceptionCaseLine::CONDITION_UNKNOWN, 'action' => ExceptionCaseLine::ACTION_INVESTIGATE, 'note' => ''],
    ];

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(?SalesOrder $order = null): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
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
        $this->outboundOrderId = '';
        $this->salesOrderLines = [];
    }

    public function updatedSalesOrderId(): void
    {
        $this->loadSalesOrderLines();
    }

    public function addManualLine(): void
    {
        $this->manualLines[] = ['sku_id' => '', 'qty' => 1, 'condition' => ExceptionCaseLine::CONDITION_UNKNOWN, 'action' => ExceptionCaseLine::ACTION_INVESTIGATE, 'note' => ''];
    }

    public function removeManualLine(int $index): void
    {
        unset($this->manualLines[$index]);
        $this->manualLines = array_values($this->manualLines);
    }

    public function save(): mixed
    {
        $tenantId = $this->validatedTenantId();
        $salesOrder = $this->validatedSalesOrder($tenantId);
        $outboundOrder = $this->validatedOutboundOrder($tenantId);

        validator([
            'case_type' => $this->caseType,
            'status' => $this->status,
            'reported_at' => $this->reportedAt,
            'reported_by' => $this->reportedBy,
            'note' => $this->note,
        ], [
            'case_type' => ['required', Rule::in(array_keys(ExceptionCase::typeOptions()))],
            'status' => ['required', Rule::in(array_keys(ExceptionCase::statusOptions()))],
            'reported_at' => ['nullable', 'date'],
            'reported_by' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        if (! $salesOrder && ! $outboundOrder) {
            throw ValidationException::withMessages(['salesOrderId' => __('exception_cases.validation_related_required')]);
        }

        $linePayloads = $this->validatedLinePayloads($tenantId, $salesOrder);

        if ($linePayloads === []) {
            throw ValidationException::withMessages(['lines' => __('exception_cases.validation_lines_required')]);
        }

        $case = DB::transaction(function () use ($tenantId, $salesOrder, $outboundOrder, $linePayloads): ExceptionCase {
            $case = ExceptionCase::create([
                'tenant_id' => $tenantId,
                'sales_order_id' => $salesOrder?->id,
                'outbound_order_id' => $outboundOrder?->id,
                'case_no' => 'EC-PENDING-'.Str::uuid(),
                'case_type' => $this->caseType,
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

            $case->update(['case_no' => ExceptionCase::buildCaseNo($case->id)]);

            return $case;
        });

        session()->flash('status', __('exception_cases.case_created'));

        return redirect()->route('exception-cases.show', $case);
    }

    public function render()
    {
        $tenantId = $this->tenantId !== '' ? (int) $this->tenantId : null;

        return view('livewire.exception-case-create', [
            'tenants' => Tenant::query()
                ->whereIn('id', $this->allowedTenantIds())
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'salesOrders' => $this->salesOrderOptions($tenantId),
            'outboundOrders' => $this->outboundOrderOptions($tenantId),
            'skuOptions' => $this->skuOptions($tenantId),
            'types' => ExceptionCase::typeOptions(),
            'statuses' => ExceptionCase::statusOptions(),
            'conditions' => ExceptionCaseLine::conditionOptions(),
            'actions' => ExceptionCaseLine::actionOptions(),
            'showTenantSelect' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('exception_cases.create_page_title'),
            'subtitle' => __('exception_cases.create_page_subtitle'),
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
                'condition' => ExceptionCaseLine::CONDITION_UNKNOWN,
                'action' => ExceptionCaseLine::ACTION_INVESTIGATE,
                'note' => '',
            ])
            ->all();
    }

    private function validatedTenantId(): int
    {
        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenantId' => __('exception_cases.validation_invalid_tenant')]);
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
        $conditionValues = array_keys(ExceptionCaseLine::conditionOptions());
        $actionValues = array_keys(ExceptionCaseLine::actionOptions());

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
                throw ValidationException::withMessages(['lines' => __('exception_cases.validation_qty_exceeds_source')]);
            }

            if ($salesOrder && $sourceLine->sales_order_id !== $salesOrder->id) {
                throw ValidationException::withMessages(['lines' => __('exception_cases.validation_invalid_source_line')]);
            }

            if (! in_array($line['condition'] ?? '', $conditionValues, true) || ! in_array($line['action'] ?? '', $actionValues, true)) {
                throw ValidationException::withMessages(['lines' => __('exception_cases.validation_invalid_line_values')]);
            }

            $payloads[] = [
                'tenant_id' => $tenantId,
                'sales_order_line_id' => $sourceLine->id,
                'sku_id' => $sourceLine->sku_id,
                'stock_item_id' => $sourceLine->sku?->stock_item_id,
                'qty' => $qty,
                'condition' => $line['condition'] ?? ExceptionCaseLine::CONDITION_UNKNOWN,
                'action' => $line['action'] ?? ExceptionCaseLine::ACTION_INVESTIGATE,
                'note' => $this->nullableString($line['note'] ?? ''),
            ];
        }

        foreach ($this->manualLines as $line) {
            if (($line['sku_id'] ?? '') === '') {
                continue;
            }

            $sku = Sku::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail((int) $line['sku_id']);
            $qty = (int) ($line['qty'] ?? 0);

            if ($qty < 1) {
                throw ValidationException::withMessages(['manualLines' => __('exception_cases.validation_qty_min')]);
            }

            if (! in_array($line['condition'] ?? '', $conditionValues, true) || ! in_array($line['action'] ?? '', $actionValues, true)) {
                throw ValidationException::withMessages(['manualLines' => __('exception_cases.validation_invalid_line_values')]);
            }

            $payloads[] = [
                'tenant_id' => $tenantId,
                'sku_id' => $sku->id,
                'stock_item_id' => $sku->stock_item_id,
                'qty' => $qty,
                'condition' => $line['condition'] ?? ExceptionCaseLine::CONDITION_UNKNOWN,
                'action' => $line['action'] ?? ExceptionCaseLine::ACTION_INVESTIGATE,
                'note' => $this->nullableString($line['note'] ?? ''),
            ];
        }

        return $payloads;
    }

    private function salesOrderOptions(?int $tenantId)
    {
        return SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->latest()
            ->limit(100)
            ->get(['id', 'tenant_id', 'platform_order_id']);
    }

    private function outboundOrderOptions(?int $tenantId)
    {
        return OutboundOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->latest()
            ->limit(100)
            ->get(['id', 'tenant_id', 'ref']);
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

        return $this->allowedTenantIdsCache = $user
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
