<?php

namespace App\Livewire;

use App\Models\ShippingMethod;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Fulfillment\FulfillmentPackService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class FulfillmentPackStart extends Component
{
    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'shipping_method_id', except: '')]
    public string $shippingMethodId = '';

    public string $scan = '';

    public ?string $lastScan = null;

    public ?string $message = null;

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeInternalUser();

        $warehouses = $this->warehouseOptions();

        if ($this->warehouseId === '' && $warehouses->count() === 1) {
            $this->warehouseId = (string) $warehouses->first()->id;
        }
    }

    public function search(FulfillmentPackService $service)
    {
        $this->authorizeInternalUser();
        $this->lastScan = trim($this->scan) === '' ? null : trim($this->scan);

        if (! $this->filtersReady()) {
            $this->message = __('fulfillment_pack.select_station_first');
            $this->dispatch('pack-scan-focus');

            return null;
        }

        $result = $service->findGroupForTrackingNo(
            trackingNo: $this->scan,
            allowedTenantIds: $this->allowedTenantIds(),
            warehouseId: (int) $this->warehouseId,
            shippingMethodId: (int) $this->shippingMethodId,
        );
        $this->scan = '';

        if ($result->status === 'found' && $result->group) {
            return $this->redirectRoute('fulfillment-groups.pack', $result->group, navigate: true);
        }

        $this->message = match ($result->status) {
            'multiple' => __('fulfillment_pack.multiple_matches'),
            'already_shipped' => __('fulfillment_pack.already_shipped'),
            'cancelled' => __('fulfillment_pack.cancelled_group'),
            default => __('fulfillment_pack.not_found_for_station'),
        };

        $this->dispatch('pack-scan-focus');

        return null;
    }

    public function updatedWarehouseId(): void
    {
        $this->focusScannerWhenReady();
    }

    public function updatedShippingMethodId(): void
    {
        $this->focusScannerWhenReady();
    }

    public function render()
    {
        $this->authorizeInternalUser();

        return view('livewire.fulfillment-pack-start', [
            'warehouses' => $this->warehouseOptions(),
            'shippingMethods' => $this->shippingMethodOptions(),
            'filtersReady' => $this->filtersReady(),
        ])
            ->layout('inventory', [
                'title' => __('fulfillment_pack.start_page_title'),
                'subtitle' => __('fulfillment_pack.page_title'),
            ]);
    }

    private function authorizeInternalUser(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
    }

    private function filtersReady(): bool
    {
        return (int) $this->warehouseId > 0 && (int) $this->shippingMethodId > 0;
    }

    private function focusScannerWhenReady(): void
    {
        if ($this->filtersReady()) {
            $this->dispatch('pack-scan-focus');
        }
    }

    private function warehouseOptions()
    {
        return Warehouse::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function shippingMethodOptions()
    {
        return ShippingMethod::query()
            ->where('shipping_methods.status', 'active')
            ->with('carrier:id,code,name')
            ->ordered()
            ->get(['shipping_methods.id', 'shipping_methods.carrier_id', 'shipping_methods.code', 'shipping_methods.name']);
    }
}
