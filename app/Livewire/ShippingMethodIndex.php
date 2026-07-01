<?php

namespace App\Livewire;

use App\Models\Carrier;
use App\Models\ShippingMethod;
use App\Support\CourierCarrier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ShippingMethodIndex extends Component
{
    use WithPagination;

    #[Url(as: 'carrier', except: '')]
    public string $carrierId = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $editingCarrierId = null;

    public string $carrierCode = '';

    public string $carrierName = '';

    public string $carrierCountryCode = 'JP';

    public string $carrierSortOrder = '';

    public string $carrierStatus = 'active';

    public array $carrierSortOrders = [];

    public array $methodSortOrders = [];

    public array $methodSelectionPriorities = [];

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    public function updatedCarrierId(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleStatus(int $methodId): void
    {
        $method = ShippingMethod::findOrFail($methodId);
        $method->update(['status' => $method->status === 'active' ? 'inactive' : 'active']);

        session()->flash('status', __('shipping.status_updated'));
    }

    public function saveCarrier(): void
    {
        $this->normalizeCarrierForm();
        $this->validateCarrierInput();

        $payload = [
            'code' => $this->carrierCode,
            'name' => trim($this->carrierName),
            'country_code' => $this->nullableString($this->carrierCountryCode),
            'sort_order' => $this->carrierSortOrder === ''
                ? $this->nextCarrierSortOrder()
                : (int) $this->carrierSortOrder,
            'status' => $this->carrierStatus,
        ];

        $this->editingCarrierId
            ? Carrier::query()->findOrFail($this->editingCarrierId)->update($payload)
            : Carrier::query()->create($payload);

        session()->flash('status', $this->editingCarrierId
            ? __('shipping.carrier_updated')
            : __('shipping.carrier_created'));

        $this->resetCarrierForm();
    }

    public function editCarrier(int $carrierId): void
    {
        $carrier = Carrier::query()->findOrFail($carrierId);

        $this->editingCarrierId = $carrier->id;
        $this->carrierCode = $carrier->code;
        $this->carrierName = $carrier->name;
        $this->carrierCountryCode = $carrier->country_code ?? '';
        $this->carrierSortOrder = (string) $carrier->sort_order;
        $this->carrierStatus = $carrier->status;
    }

    public function resetCarrierForm(): void
    {
        $this->reset('editingCarrierId', 'carrierCode', 'carrierName', 'carrierCountryCode', 'carrierSortOrder');
        $this->carrierCountryCode = 'JP';
        $this->carrierStatus = 'active';
        $this->resetValidation();
    }

    public function toggleCarrierStatus(int $carrierId): void
    {
        $carrier = Carrier::query()->findOrFail($carrierId);
        $carrier->update(['status' => $carrier->status === 'active' ? 'inactive' : 'active']);

        if ((int) $this->carrierId === $carrier->id && $carrier->status !== 'active') {
            $this->carrierId = '';
        }

        session()->flash('status', __('shipping.carrier_status_updated'));
    }

    public function saveCarrierOrder(): void
    {
        $this->validate([
            'carrierSortOrders' => ['array'],
            'carrierSortOrders.*' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        foreach ($this->carrierSortOrders as $carrierId => $sortOrder) {
            if (! ctype_digit((string) $carrierId)) {
                continue;
            }

            Carrier::query()
                ->whereKey((int) $carrierId)
                ->update(['sort_order' => (int) $sortOrder]);
        }

        session()->flash('status', __('shipping.order_updated'));
    }

    public function saveMethodOrder(): void
    {
        $this->validate([
            'methodSortOrders' => ['array'],
            'methodSortOrders.*' => ['required', 'integer', 'min:0', 'max:65535'],
            'methodSelectionPriorities' => ['array'],
            'methodSelectionPriorities.*' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        foreach ($this->methodSortOrders as $methodId => $sortOrder) {
            if (! ctype_digit((string) $methodId)) {
                continue;
            }

            ShippingMethod::query()
                ->whereKey((int) $methodId)
                ->update([
                    'sort_order' => (int) $sortOrder,
                    'selection_priority' => (int) ($this->methodSelectionPriorities[$methodId] ?? 0),
                ]);
        }

        session()->flash('status', __('shipping.order_updated'));
    }

    public function render()
    {
        $methods = ShippingMethod::query()
            ->with(['carrier', 'rates' => fn ($query) => $query->whereNull('tenant_id')->where('rate_type', 'flat')])
            ->when($this->carrierId !== '', fn ($query) => $query->where('carrier_id', (int) $this->carrierId))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('shipping_methods.status', $this->statusFilter))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($query) => $query
                    ->where('shipping_methods.code', 'like', $like)
                    ->orWhere('shipping_methods.name', 'like', $like)
                    ->orWhere('shipping_methods.name_ja', 'like', $like)
                    ->orWhere('shipping_methods.name_zh_tw', 'like', $like)
                    ->orWhere('shipping_methods.name_zh_cn', 'like', $like));
            })
            ->ordered()
            ->paginate(30);

        $carrierRows = Carrier::query()->withCount('shippingMethods')->ordered()->get();
        $this->syncSortInputs($carrierRows, $methods->getCollection());

        return view('livewire.shipping-method-index', [
            'methods' => $methods,
            'carriers' => Carrier::ordered()->get(['id', 'code', 'name']),
            'carrierRows' => $carrierRows,
            'carrierCodeOptions' => $this->carrierCodeOptions(),
            'statuses' => $this->statuses(),
        ])->layout('inventory', [
            'title' => __('shipping.index_page_title'),
            'subtitle' => __('shipping.index_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function statusLabel(string $status): string
    {
        return $this->statuses()[$status] ?? str($status)->replace('_', ' ')->title()->toString();
    }

    public function statusColor(string $status): string
    {
        return $status === 'active' ? 'green' : 'zinc';
    }

    private function statuses(): array
    {
        return [
            'active' => __('shipping.status_active'),
            'inactive' => __('shipping.status_inactive'),
        ];
    }

    private function validateCarrierInput(): void
    {
        validator([
            'carrier_code' => $this->carrierCode,
            'carrier_name' => $this->carrierName,
            'carrier_country_code' => $this->carrierCountryCode,
            'carrier_sort_order' => $this->carrierSortOrder,
            'carrier_status' => $this->carrierStatus,
        ], [
            'carrier_code' => ['required', Rule::in(array_keys($this->carrierCodeOptions())), Rule::unique('carriers', 'code')->ignore($this->editingCarrierId)],
            'carrier_name' => ['required', 'string', 'max:255'],
            'carrier_country_code' => ['nullable', 'string', 'size:2'],
            'carrier_sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'carrier_status' => ['required', Rule::in(array_keys($this->statuses()))],
        ])->validate();
    }

    private function normalizeCarrierForm(): void
    {
        $this->carrierCode = CourierCarrier::normalize(str($this->carrierCode)->trim()->lower()->toString()) ?? '';
        $this->carrierCountryCode = strtoupper(trim($this->carrierCountryCode));
    }

    private function carrierCodeOptions(): array
    {
        return [
            CourierCarrier::YAMATO => 'Yamato',
            CourierCarrier::SAGAWA => 'Sagawa',
            'japan_post' => 'Japan Post',
            'other' => 'Other',
        ];
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nextCarrierSortOrder(): int
    {
        return ((int) Carrier::query()->max('sort_order')) + 10;
    }

    private function syncSortInputs($carrierRows, $methodRows): void
    {
        foreach ($carrierRows as $carrier) {
            $this->carrierSortOrders[$carrier->id] ??= (string) $carrier->sort_order;
        }

        foreach ($methodRows as $method) {
            $this->methodSortOrders[$method->id] ??= (string) $method->sort_order;
            $this->methodSelectionPriorities[$method->id] ??= (string) $method->selection_priority;
        }
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }
}
