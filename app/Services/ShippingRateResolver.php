<?php

namespace App\Services;

use App\Models\ShippingMethod;
use App\Models\ShippingMethodRate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ShippingRateResolver
{
    public function resolve(?int $tenantId, ShippingMethod|int $shippingMethod, CarbonInterface|string $date): ?ShippingMethodRate
    {
        $shippingMethodId = $shippingMethod instanceof ShippingMethod ? $shippingMethod->id : $shippingMethod;
        $date = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();

        return ShippingMethodRate::query()
            ->where('shipping_method_id', $shippingMethodId)
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereNull('effective_from')
                ->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date))
            ->where(fn ($query) => $query
                ->whereNull('tenant_id')
                ->when($tenantId !== null, fn ($query) => $query->orWhere('tenant_id', $tenantId)))
            ->orderByRaw('case when tenant_id = ? then 0 when tenant_id is null then 1 else 2 end', [$tenantId ?? 0])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
