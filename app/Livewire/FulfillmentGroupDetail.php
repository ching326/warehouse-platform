<?php

namespace App\Livewire;

use App\Models\FulfillmentGroup;
use App\Models\SalesOrderLine;
use App\Models\Tenant;
use App\Support\TrackingNumber;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class FulfillmentGroupDetail extends Component
{
    public int $groupId = 0;

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

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(FulfillmentGroup $group): void
    {
        $this->authorizeTenantAccess();

        if (! in_array($group->tenant_id, $this->allowedTenantIds(), true)) {
            abort(403);
        }

        $this->groupId = $group->id;
    }

    public function editRecipient(): void
    {
        $group = $this->loadGroup();
        $this->recipientName = (string) $group->recipient_name;
        $this->recipientPhone = (string) $group->recipient_phone;
        $this->recipientCountryCode = (string) $group->recipient_country_code;
        $this->recipientPostalCode = (string) $group->recipient_postal_code;
        $this->recipientState = (string) $group->recipient_state;
        $this->recipientCity = (string) $group->recipient_city;
        $this->recipientAddressLine1 = (string) $group->recipient_address_line1;
        $this->recipientAddressLine2 = (string) $group->recipient_address_line2;
        $this->editingRecipient = true;
    }

    public function cancelEditRecipient(): void
    {
        $this->editingRecipient = false;
    }

    public function saveRecipient(): void
    {
        $group = $this->loadGroup();
        $this->ensureReserved($group);
        $this->recipientCountryCode = strtoupper(trim($this->recipientCountryCode));

        validator($this->recipientData(), [
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

        $group->update($data);
        $group->outboundOrder?->update($data);

        $this->editingRecipient = false;
        session()->flash('status', __('fulfillment_groups.recipient_updated'));
    }

    public function editShipping(): void
    {
        $group = $this->loadGroup();
        $this->courier = (string) $group->courier;
        $this->trackingNo = (string) $group->tracking_no;
        $this->note = (string) $group->note;
        $this->editingShipping = true;
    }

    public function cancelEditShipping(): void
    {
        $this->editingShipping = false;
    }

    public function saveShipping(): void
    {
        $group = $this->loadGroup();
        $this->ensureReserved($group);

        validator([
            'courier' => $this->courier,
            'tracking_no' => $this->trackingNo,
            'note' => $this->note,
        ], [
            'courier' => ['nullable', 'string', 'max:100'],
            'tracking_no' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $group->update([
            'courier' => $this->nullableString($this->courier),
            'tracking_no' => TrackingNumber::normalize($this->trackingNo),
            'note' => $this->nullableString($this->note),
        ]);

        $this->editingShipping = false;
        session()->flash('status', __('fulfillment_groups.shipping_updated'));
    }

    public function statusLabel(string $status): string
    {
        return [
            FulfillmentGroup::STATUS_RESERVED => __('fulfillment_groups.status_reserved'),
            FulfillmentGroup::STATUS_SHIPPED => __('fulfillment_groups.status_shipped'),
            FulfillmentGroup::STATUS_CANCELLED => __('fulfillment_groups.status_cancelled'),
        ][$status] ?? $status;
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            FulfillmentGroup::STATUS_SHIPPED => 'green',
            FulfillmentGroup::STATUS_CANCELLED => 'red',
            default => 'blue',
        };
    }

    public function render()
    {
        $group = FulfillmentGroup::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'outboundOrder:id,fulfillment_group_id,ref,status',
                'orders.lines.sku.stockItem',
                'packScans' => fn ($query) => $query
                    ->with(['sku:id,sku,name', 'stockItem:id,code,name,short_name', 'scannedBy:id,name'])
                    ->limit(10),
            ])
            ->findOrFail($this->groupId);

        return view('livewire.fulfillment-group-detail', [
            'group' => $group,
            'combinedLines' => $this->combinedLines($group),
        ])->layout('inventory', [
            'title' => __('fulfillment_groups.detail_page_title'),
            'subtitle' => $group->reference_no,
        ]);
    }

    private function loadGroup(): FulfillmentGroup
    {
        return FulfillmentGroup::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with('outboundOrder')
            ->findOrFail($this->groupId);
    }

    private function ensureReserved(FulfillmentGroup $group): void
    {
        if ($group->status !== FulfillmentGroup::STATUS_RESERVED) {
            abort(403);
        }
    }

    private function recipientData(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'recipient_country_code' => $this->recipientCountryCode,
            'recipient_postal_code' => $this->recipientPostalCode,
            'recipient_state' => $this->recipientState,
            'recipient_city' => $this->recipientCity,
            'recipient_address_line1' => $this->recipientAddressLine1,
            'recipient_address_line2' => $this->recipientAddressLine2,
        ];
    }

    private function combinedLines(FulfillmentGroup $group): array
    {
        $lines = [];

        foreach ($group->orders as $order) {
            foreach ($order->lines as $line) {
                if ($line->line_status !== SalesOrderLine::STATUS_READY) {
                    continue;
                }

                $sku = $line->sku;
                if (! $sku) {
                    continue;
                }

                $lines[$sku->id] ??= [
                    'sku' => $sku,
                    'stockItem' => $sku->stockItem,
                    'quantity' => 0,
                ];
                $lines[$sku->id]['quantity'] += $line->quantity;
            }
        }

        return array_values($lines);
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

    private function authorizeTenantAccess(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
