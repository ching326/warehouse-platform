<?php

namespace App\Http\Controllers;

use App\Exports\SalesOrdersExport;
use App\Models\Shop;
use App\Models\Tenant;
use App\Support\SalesOrderFilters;
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

        $idsParam = trim((string) $request->query('ids', ''));
        $hasOrderIdFilter = $request->query->has('ids') && $idsParam !== '';
        $orderIds = $idsParam === ''
            ? []
            : array_values(array_unique(array_filter(
                array_map('intval', explode(',', $idsParam)),
                fn (int $id) => $id > 0
            )));

        $filters = SalesOrderFilters::normalize([
            'allowed_tenant_ids' => $allowedTenantIds,
            'has_order_id_filter' => $hasOrderIdFilter,
            'order_ids' => $orderIds,
            'platforms' => $request->query('platforms', $request->query('platform', [])),
            'shops' => $request->query('shops', $request->query('shop', [])),
            'fulfillment' => $request->query('fulfillment', []),
            'order_status' => $request->query('order_status', []),
            'shipping' => $request->query('shipping', []),
            'others' => $request->query('others', []),
            'date_range' => $request->query('date_range', SalesOrderFilters::DATE_ALL),
            'active_only' => $request->query('active_only', true),
            'date_from' => $request->query('date_from', ''),
            'date_to' => $request->query('date_to', ''),
            'print_waiting' => $request->query('print_waiting', false),
            'q' => $request->query('q', ''),
        ]);

        $filters['shop_filter_allowed'] = $this->shopFilterAllowed($filters['shops'], $allowedTenantIds);

        if (! $hasOrderIdFilter) {
            if (SalesOrderFilters::dateRangeError($filters)) {
                abort(422, __('sales_orders.date_range_too_wide'));
            }

            if (SalesOrderFilters::requiresExplicitDateRange($filters)) {
                abort(422, __('sales_orders.export_requires_date_range'));
            }
        }

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

    private function shopFilterAllowed(array $shopIds, array $allowedTenantIds): bool
    {
        if ($shopIds === []) {
            return true;
        }

        return Shop::query()
            ->whereIn('tenant_id', $allowedTenantIds)
            ->whereIn('id', array_map('intval', $shopIds))
            ->count() === count(array_unique($shopIds));
    }
}
