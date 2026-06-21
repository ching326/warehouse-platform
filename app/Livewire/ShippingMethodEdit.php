<?php

namespace App\Livewire;

use App\Models\Carrier;
use App\Models\ShippingMethod;
use Illuminate\Support\Facades\DB;

class ShippingMethodEdit extends ShippingMethodCreate
{
    public ShippingMethod $method;

    public function mount(?ShippingMethod $method = null): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }

        $method ??= ShippingMethod::query()->findOrFail(request()->route('method'));
        $method->load(['rates', 'marketplaceMappings']);
        $this->method = $method;
        $this->carrierId = (string) $method->carrier_id;
        $this->code = $method->code;
        $this->name = $method->name;
        $this->serviceType = $method->service_type ?? '';
        $this->sortOrder = (string) $method->sort_order;
        $this->selectionPriority = (string) $method->selection_priority;
        $this->isTrackable = $method->is_trackable;
        $this->requiresSize = $method->requires_size;
        $this->requiresZone = $method->requires_zone;
        $this->supportsCourierCsv = $method->supports_courier_csv;
        $this->status = $method->status;
        $this->note = $method->note ?? '';

        $rate = $method->rates->first(fn ($rate) => $rate->tenant_id === null && $rate->rate_type === 'flat');
        $this->flatFee = $rate?->price ?? '';
        $this->currency = $rate?->currency ?? 'JPY';

        $mapping = $method->marketplaceMappings->first();
        $this->mappingPlatform = $mapping?->platform ?? '';
        $this->mappingMarketplace = $mapping?->marketplace ?? '';
        $this->mappingCarrierCode = $mapping?->carrier_code ?? '';
        $this->mappingCarrierName = $mapping?->carrier_name ?? '';
        $this->mappingServiceCode = $mapping?->service_code ?? '';
        $this->mappingServiceName = $mapping?->service_name ?? '';
    }

    public function save()
    {
        $this->normalize();
        $this->validateInput($this->method->id);

        DB::transaction(function () {
            $this->method->update($this->methodPayload());
            $this->saveRate($this->method);
            $this->saveMapping($this->method);
        });

        session()->flash('status', __('shipping.method_updated'));

        return redirect()->route('setup.shipping-methods.index');
    }

    public function saveMarketplaceMapping(): void
    {
        $this->normalize();

        validator($this->validationData(), [
            'mapping_platform' => ['required', \Illuminate\Validation\Rule::in(array_keys($this->mappingPlatforms()))],
            'mapping_marketplace' => ['nullable', \Illuminate\Validation\Rule::in(array_keys($this->mappingMarketplaces()))],
            'mapping_carrier_code' => ['nullable', 'string', 'max:100'],
            'mapping_carrier_name' => ['nullable', 'string', 'max:255'],
            'mapping_service_code' => ['nullable', 'string', 'max:100'],
            'mapping_service_name' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $this->saveMapping($this->method);
        $this->method->load('marketplaceMappings');
        $this->resetMappingForm();

        session()->flash('status', __('shipping.mapping_saved'));
    }

    public function render()
    {
        return view('livewire.shipping-method-edit', [
            'carriers' => Carrier::ordered()->get(['id', 'code', 'name']),
            'statuses' => $this->statuses(),
            'method' => $this->method,
            'marketplaceMappings' => $this->method->marketplaceMappings()
                ->orderBy('platform')
                ->orderBy('marketplace')
                ->get(),
        ])->layout('inventory', [
            'title' => __('shipping.edit_page_title'),
            'subtitle' => $this->method->code,
        ]);
    }
}
