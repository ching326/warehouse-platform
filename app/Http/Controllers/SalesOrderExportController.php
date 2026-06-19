<?php

namespace App\Http\Controllers;

use App\Exports\SalesOrdersExport;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesOrderExportController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $allowedTenantIds = $this->allowedTenantIds();

        if (! $this->isInternalUser() && $allowedTenantIds === []) {
            abort(403);
        }

        $shopId = trim((string) $request->query('shop', ''));
        $shopFilterAllowed = true;

        if ($shopId !== '') {
            $shopFilterAllowed = Shop::query()
                ->whereIn('tenant_id', $allowedTenantIds)
                ->whereKey((int) $shopId)
                ->exists();
        }

        $idsParam = trim((string) $request->query('ids', ''));
        $hasOrderIdFilter = $request->query->has('ids') && $idsParam !== '';
        $orderIds = $idsParam === ''
            ? []
            : array_values(array_unique(array_filter(
                array_map('intval', explode(',', $idsParam)),
                fn (int $id) => $id > 0
            )));

        $filters = [
            'allowed_tenant_ids' => $allowedTenantIds,
            'has_order_id_filter' => $hasOrderIdFilter,
            'order_ids' => $orderIds,
            'shop_id' => $shopId,
            'shop_filter_allowed' => $shopFilterAllowed,
            'fulfillment' => trim((string) $request->query('fulfillment', '')),
            'order_status' => trim((string) $request->query('order_status', '')),
            'search' => trim((string) $request->query('q', '')),
        ];

        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';
        $writer = $format === 'xlsx' ? ExcelFormat::XLSX : ExcelFormat::CSV;
        $filename = 'sales-orders-'.now()->format('Ymd-His').'.'.$format;

        return Excel::download(new SalesOrdersExport($filters), $filename, $writer);
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->isInternalUser()) {
            return Tenant::query()->pluck('id')->all();
        }

        return Auth::user()
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }
}
