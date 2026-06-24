<?php

namespace App\Livewire;

use App\Models\AmazonSpapiConnection;
use App\Models\AmazonSpapiImportRun;
use App\Models\FulfillmentGroupOrder;
use App\Models\SalesOrder;
use App\Models\Shop;
use App\Services\Amazon\AmazonOrderMapper;
use App\Services\Amazon\AmazonSpapiApiException;
use App\Services\Amazon\AmazonSpapiOrdersClient;
use App\Services\SalesOrders\SalesOrderImporter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class AmazonSpapiOrderImport extends Component
{
    public string $shopId = '';
    public string $windowType = AmazonSpapiImportRun::WINDOW_LAST_UPDATED;
    public string $windowFrom = '';
    public string $windowTo = '';
    public array $parsedRows = [];
    public bool $parsed = false;
    public bool $hasErrors = false;
    public ?string $warning = null;

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }

        $this->resetWindow();
    }

    public function updatedShopId(): void
    {
        $this->resetPreview();
    }

    public function updatedWindowType(): void
    {
        $this->resetPreview();
    }

    public function updatedWindowFrom(): void
    {
        $this->resetPreview();
    }

    public function updatedWindowTo(): void
    {
        $this->resetPreview();
    }

    public function useDefaultWindow(): void
    {
        $connection = $this->selectedConnection();
        $to = now()->subMinutes(2);
        $from = $connection?->last_orders_import_window_to
            ? $connection->last_orders_import_window_to->copy()->subMinutes(10)
            : $to->copy()->subDay();

        $this->windowFrom = $from->format('Y-m-d\TH:i');
        $this->windowTo = $to->format('Y-m-d\TH:i');
        $this->resetPreview();
    }

    public function resetForm(): void
    {
        $this->shopId = '';
        $this->windowType = AmazonSpapiImportRun::WINDOW_LAST_UPDATED;
        $this->resetWindow();
        $this->resetPreview();
    }

    public function fetchPreview(AmazonSpapiOrdersClient $client, AmazonOrderMapper $mapper): void
    {
        $connection = $this->validatedConnection();
        [$from, $to] = $this->validatedWindow();

        try {
            $result = $client->fetch($connection, $this->windowType, $from, $to);
            $rows = $mapper->map($connection->shop, $result['orders'], $result['items']);
        } catch (AmazonSpapiApiException $exception) {
            $this->resetPreview();
            $this->addError('api', $exception->getMessage());

            return;
        }

        $this->parsedRows = $rows;
        $this->parsed = true;
        $this->hasErrors = collect($rows)->contains(fn ($row) => ($row['errors'] ?? []) !== []);
        $this->warning = $result['capped'] ? __('amazon_spapi_import.manual_cap_hit') : null;
    }

    public function confirmImport()
    {
        if (! $this->parsed || $this->parsedRows === []) {
            session()->flash('error', __('sales_orders.import_nothing_to_import'));

            return;
        }

        if ($this->hasErrors || $this->missingSkuCount() > 0) {
            session()->flash('error', __('amazon_spapi_import.missing_sku_blocks_import'));

            return;
        }

        $connection = $this->validatedConnection();
        [$from, $to] = $this->validatedWindow();
        $shop = $connection->shop;
        $summary = $this->summary();
        $importer = app(SalesOrderImporter::class);

        try {
            $result = DB::transaction(function () use ($connection, $shop, $from, $to, $summary, $importer) {
                $run = AmazonSpapiImportRun::query()->create([
                    'tenant_id' => $shop->tenant_id,
                    'shop_id' => $shop->id,
                    'amazon_spapi_connection_id' => $connection->id,
                    'triggered_by_user_id' => Auth::id(),
                    'mode' => AmazonSpapiImportRun::MODE_MANUAL,
                    'status' => AmazonSpapiImportRun::STATUS_IMPORTING,
                    'window_type' => $this->windowType,
                    'window_from' => $from,
                    'window_to' => $to,
                    'api_order_count' => $summary['api_orders'],
                    'api_line_count' => count($this->parsedRows),
                    'new_order_count' => $summary['new_orders'],
                    'new_line_count' => $summary['new_lines'],
                    'duplicate_order_count' => $summary['duplicates'],
                    'missing_sku_count' => $summary['missing_sku'],
                    'cancel_requested_count' => $summary['cancel_requested'],
                    'skipped_order_count' => $summary['skipped'],
                    'started_at' => now(),
                ]);

                $updatedExisting = $this->applyExistingCancelRequests($shop);
                $importResult = $importer->import($shop, $this->parsedRows, Auth::id());

                $run->update([
                    'status' => AmazonSpapiImportRun::STATUS_COMPLETED,
                    'imported_order_count' => $importResult->importedOrders,
                    'skipped_order_count' => $summary['skipped'] + $importResult->skippedDuplicates + $updatedExisting,
                    'completed_at' => now(),
                ]);

                $connection->update([
                    'last_orders_imported_at' => now(),
                    'last_orders_import_window_from' => $from,
                    'last_orders_import_window_to' => $to,
                    'last_orders_import_status' => 'success',
                    'last_orders_import_error' => null,
                ]);

                return $importResult;
            });
        } catch (QueryException $exception) {
            if (! $importer->isDuplicateOrderConstraintViolation($exception)) {
                throw $exception;
            }

            session()->flash('error', __('sales_orders.import_duplicate_race_retry'));

            return;
        }

        $this->resetPreview();

        if ($result->importedOrders === 0) {
            session()->flash('status', __('amazon_spapi_import.no_new_orders'));

            return;
        }

        session()->flash('status', __('amazon_spapi_import.import_succeeded', [
            'orders' => $result->importedOrders,
            'skipped' => $result->skippedDuplicates,
        ]));

        return redirect()->route('sales.orders.index');
    }

    public function render()
    {
        return view('livewire.amazon-spapi-order-import', [
            'shops' => $this->shopOptions(),
            'summary' => $this->summary(),
        ])->layout('inventory', [
            'title' => __('amazon_spapi_import.page_title'),
            'subtitle' => __('amazon_spapi_import.page_subtitle'),
        ]);
    }

    private function validatedConnection(): AmazonSpapiConnection
    {
        $shop = $this->validatedShop();
        $connection = $shop->amazonSpapiConnection()->first();

        if (! $connection) {
            throw ValidationException::withMessages(['shopId' => __('amazon_spapi_import.no_connection')]);
        }

        if ($connection->status !== AmazonSpapiConnection::STATUS_CONNECTED) {
            throw ValidationException::withMessages(['shopId' => __('amazon_spapi_import.connection_not_ready')]);
        }

        $expectedRegion = \App\Support\AmazonSpapiRegion::regionForMarketplaceId($connection->marketplace_id);
        if ($expectedRegion !== null && $expectedRegion !== $connection->region) {
            throw ValidationException::withMessages(['shopId' => __('amazon_spapi_import.marketplace_region_mismatch')]);
        }

        return $connection->load('shop');
    }

    private function selectedConnection(): ?AmazonSpapiConnection
    {
        if ($this->shopId === '') {
            return null;
        }

        return Shop::query()
            ->where('platform', 'amazon')
            ->where('status', 'active')
            ->with('amazonSpapiConnection')
            ->find((int) $this->shopId)
            ?->amazonSpapiConnection;
    }

    private function validatedShop(): Shop
    {
        if ($this->shopId === '') {
            throw ValidationException::withMessages(['shopId' => __('sales_orders.shop_required')]);
        }

        $shop = Shop::query()
            ->where('status', 'active')
            ->where('platform', 'amazon')
            ->with('amazonSpapiConnection')
            ->find((int) $this->shopId);

        if (! $shop) {
            throw ValidationException::withMessages(['shopId' => __('amazon_spapi_import.amazon_shop_only')]);
        }

        return $shop;
    }

    private function validatedWindow(): array
    {
        validator([
            'window_type' => $this->windowType,
            'window_from' => $this->windowFrom,
            'window_to' => $this->windowTo,
        ], [
            'window_type' => ['required', 'in:last_updated,created'],
            'window_from' => ['required', 'date'],
            'window_to' => ['required', 'date'],
        ])->validate();

        $from = Carbon::parse($this->windowFrom);
        $to = Carbon::parse($this->windowTo);

        if ($to->greaterThan(now()->subMinutes(2))) {
            throw ValidationException::withMessages(['windowTo' => __('amazon_spapi_import.window_to_too_recent')]);
        }

        if ($from->greaterThanOrEqualTo($to)) {
            throw ValidationException::withMessages(['windowFrom' => __('amazon_spapi_import.window_invalid')]);
        }

        if ($from->diffInSeconds($to, true) > 7 * 24 * 60 * 60) {
            throw ValidationException::withMessages(['windowFrom' => __('amazon_spapi_import.window_too_large')]);
        }

        return [$from, $to];
    }

    private function applyExistingCancelRequests(Shop $shop): int
    {
        $count = 0;
        $orderIds = collect($this->parsedRows)
            ->filter(fn ($row) => ($row['preview_status'] ?? '') === 'existing_cancel_requested')
            ->pluck('platform_order_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($orderIds as $platformOrderId) {
            $order = SalesOrder::query()
                ->where('tenant_id', $shop->tenant_id)
                ->where('shop_id', $shop->id)
                ->where('platform_order_id', $platformOrderId)
                ->first();

            if (! $order || ! $this->canApplyCancelRequest($order)) {
                continue;
            }

            $order->update(['order_status' => SalesOrder::ORDER_STATUS_CANCEL_REQUESTED]);
            $count++;
        }

        return $count;
    }

    private function canApplyCancelRequest(SalesOrder $order): bool
    {
        if (in_array($order->order_status, [SalesOrder::ORDER_STATUS_COMPLETED, SalesOrder::ORDER_STATUS_CANCELLED], true)) {
            return false;
        }

        if (in_array($order->fulfillment_status, [
            SalesOrder::FULFILLMENT_STATUS_ARRANGED,
            SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            SalesOrder::FULFILLMENT_STATUS_CANCELLED,
        ], true)) {
            return false;
        }

        return ! FulfillmentGroupOrder::query()->where('sales_order_id', $order->id)->exists();
    }

    private function shopOptions()
    {
        return Shop::query()
            ->where('platform', 'amazon')
            ->where('status', 'active')
            ->with(['tenant:id,code', 'amazonSpapiConnection'])
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'platform', 'marketplace', 'code', 'name']);
    }

    private function summary(): array
    {
        $rows = collect($this->parsedRows);
        $orderGroups = $rows->filter(fn ($row) => filled($row['platform_order_id'] ?? ''))->groupBy('platform_order_id');

        return [
            'api_orders' => $orderGroups->count(),
            'new_orders' => $orderGroups->filter(fn ($group) => $group->every(fn ($row) => ($row['preview_status'] ?? '') === 'ready'))->count(),
            'new_lines' => $rows->filter(fn ($row) => ($row['preview_status'] ?? '') === 'ready')->count(),
            'duplicates' => $orderGroups->filter(fn ($group) => $group->contains(fn ($row) => ($row['preview_status'] ?? '') === 'duplicate'))->count(),
            'missing_sku' => $this->missingSkuCount(),
            'cancel_requested' => $orderGroups->filter(fn ($group) => $group->contains(fn ($row) => ($row['order_status'] ?? '') === SalesOrder::ORDER_STATUS_CANCEL_REQUESTED))->count(),
            'skipped' => $orderGroups->filter(fn ($group) => $group->contains(fn ($row) => in_array(($row['preview_status'] ?? ''), ['not_actionable', 'api_warning', 'existing_cancel_requested'], true)))->count(),
        ];
    }

    private function missingSkuCount(): int
    {
        return collect($this->parsedRows)
            ->filter(fn ($row) => ($row['preview_status'] ?? '') === 'missing_sku' || ($row['sku_not_found'] ?? false))
            ->count();
    }

    private function resetWindow(): void
    {
        $to = now()->subMinutes(2);
        $from = $to->copy()->subDay();

        $this->windowFrom = $from->format('Y-m-d\TH:i');
        $this->windowTo = $to->format('Y-m-d\TH:i');
    }

    private function resetPreview(): void
    {
        $this->parsedRows = [];
        $this->parsed = false;
        $this->hasErrors = false;
        $this->warning = null;
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }
}
