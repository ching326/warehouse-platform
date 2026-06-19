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

    public function render()
    {
        return view('livewire.shipping-method-edit', [
            'carriers' => Carrier::orderBy('name')->get(['id', 'code', 'name']),
            'statuses' => $this->statuses(),
            'method' => $this->method,
        ])->layout('inventory', [
            'title' => __('shipping.edit_page_title'),
            'subtitle' => $this->method->code,
        ]);
    }
}
