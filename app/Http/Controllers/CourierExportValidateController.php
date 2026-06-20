<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Courier\CourierExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CourierExportValidateController extends Controller
{
    public function __invoke(Request $request, CourierExportService $service): JsonResponse
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }

        $result = $service->validateExport(
            salesOrderIds: (array) $request->input('sales_order_ids', []),
            carrier: (string) $request->input('carrier', ''),
            allowedTenantIds: $this->allowedTenantIds(),
        );

        return response()->json($result->toArray(), $result->hasHardBlock() ? 422 : 200);
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
