<?php

namespace App\Livewire;

use App\Models\Carrier;
use App\Models\ShippingMethod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ShippingMethodCreate extends Component
{
    public string $carrierId = '';
    public string $code = '';
    public string $name = '';
    public string $serviceType = '';
    public string $sortOrder = '';
    public bool $isTrackable = true;
    public bool $requiresSize = false;
    public bool $requiresZone = false;
    public bool $supportsCourierCsv = true;
    public string $status = 'active';
    public string $note = '';
    public string $flatFee = '';
    public string $currency = 'JPY';
    public string $mappingPlatform = '';
    public string $mappingMarketplace = '';
    public string $mappingCarrierCode = '';
    public string $mappingCarrierName = '';
    public string $mappingServiceCode = '';
    public string $mappingServiceName = '';

    public function mount(?ShippingMethod $method = null): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    public function save()
    {
        $this->normalize();
        $this->validateInput();

        DB::transaction(function () {
            $method = ShippingMethod::create($this->methodPayload());
            $this->saveRate($method);
            $this->saveMapping($method);
        });

        session()->flash('status', __('shipping.method_created'));

        return redirect()->route('setup.shipping-methods.index');
    }

    public function render()
    {
        return view('livewire.shipping-method-create', [
            'carriers' => Carrier::where('status', 'active')->ordered()->get(['id', 'code', 'name']),
            'statuses' => $this->statuses(),
        ])->layout('inventory', [
            'title' => __('shipping.create_page_title'),
            'subtitle' => __('shipping.index_page_subtitle'),
        ]);
    }

    protected function validateInput(?int $ignoreId = null): void
    {
        validator($this->validationData(), [
            'carrier_id' => ['required', 'integer', Rule::exists('carriers', 'id')->where('status', 'active')],
            'code' => ['required', 'string', 'max:100', Rule::unique('shipping_methods', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'service_type' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'flat_fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'mapping_platform' => ['nullable', 'string', 'max:100'],
            'mapping_marketplace' => ['nullable', 'string', 'max:100'],
            'mapping_carrier_code' => ['nullable', 'string', 'max:100'],
            'mapping_carrier_name' => ['nullable', 'string', 'max:255'],
            'mapping_service_code' => ['nullable', 'string', 'max:100'],
            'mapping_service_name' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }

    protected function normalize(): void
    {
        $this->code = str($this->code)->trim()->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->replaceMatches('/_+/', '_')->trim('_')->toString();
        $this->currency = strtoupper(trim($this->currency));
        $this->mappingMarketplace = strtoupper(trim($this->mappingMarketplace));
        $this->mappingPlatform = strtolower(trim($this->mappingPlatform));
    }

    protected function methodPayload(): array
    {
        return [
            'carrier_id' => (int) $this->carrierId,
            'code' => $this->code,
            'name' => trim($this->name),
            'service_type' => $this->nullableString($this->serviceType),
            'sort_order' => $this->sortOrder === ''
                ? $this->nextSortOrder((int) $this->carrierId)
                : (int) $this->sortOrder,
            'is_trackable' => $this->isTrackable,
            'requires_size' => $this->requiresSize,
            'requires_zone' => $this->requiresZone,
            'supports_courier_csv' => $this->supportsCourierCsv,
            'status' => $this->status,
            'note' => $this->nullableString($this->note),
        ];
    }

    protected function saveRate(ShippingMethod $method): void
    {
        if (trim($this->flatFee) === '') {
            return;
        }

        $method->rates()->updateOrCreate(
            ['tenant_id' => null, 'rate_type' => 'flat', 'currency' => $this->currency],
            ['price' => $this->flatFee, 'status' => 'active'],
        );
    }

    protected function saveMapping(ShippingMethod $method): void
    {
        if ($this->mappingPlatform === '') {
            return;
        }

        $method->marketplaceMappings()->updateOrCreate(
            ['platform' => $this->mappingPlatform, 'marketplace' => $this->mappingMarketplace],
            [
                'carrier_code' => $this->nullableString($this->mappingCarrierCode),
                'carrier_name' => $this->nullableString($this->mappingCarrierName),
                'service_code' => $this->nullableString($this->mappingServiceCode),
                'service_name' => $this->nullableString($this->mappingServiceName),
            ],
        );
    }

    protected function validationData(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'code' => $this->code,
            'name' => $this->name,
            'service_type' => $this->serviceType,
            'sort_order' => $this->sortOrder,
            'status' => $this->status,
            'note' => $this->note,
            'flat_fee' => $this->flatFee,
            'currency' => $this->currency,
            'mapping_platform' => $this->mappingPlatform,
            'mapping_marketplace' => $this->mappingMarketplace,
            'mapping_carrier_code' => $this->mappingCarrierCode,
            'mapping_carrier_name' => $this->mappingCarrierName,
            'mapping_service_code' => $this->mappingServiceCode,
            'mapping_service_name' => $this->mappingServiceName,
        ];
    }

    protected function statuses(): array
    {
        return [
            'active' => __('shipping.status_active'),
            'inactive' => __('shipping.status_inactive'),
        ];
    }

    protected function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function nextSortOrder(int $carrierId): int
    {
        return ((int) ShippingMethod::query()
            ->where('carrier_id', $carrierId)
            ->max('sort_order')) + 10;
    }

    protected function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }
}
