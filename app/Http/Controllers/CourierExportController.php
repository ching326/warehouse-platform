<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Courier\CourierExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CourierExportController extends Controller
{
    public function __invoke(Request $request, CourierExportService $service): RedirectResponse
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }

        $batch = $service->export(
            salesOrderIds: (array) $request->input('sales_order_ids', []),
            carrier: (string) $request->input('carrier', ''),
            allowedTenantIds: $this->allowedTenantIds(),
            user: Auth::user(),
            confirmedReExport: $request->boolean('confirmed_re_export'),
        );

        return redirect()->route('courier-export-batches.download', $batch);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->isInternalUser()) {
            return Tenant::query()->pluck('id')->all();
        }

        $user = Auth::user();

        if (! $user) {
            return [];
        }

        return $user
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }
}
