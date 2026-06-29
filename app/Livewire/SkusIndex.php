<?php

namespace App\Livewire;

use App\Exceptions\AliasCollisionException;
use App\Livewire\Concerns\HasEnumLabels;
use App\Models\BarcodeAlias;
use App\Models\MediaAsset;
use App\Models\PackagingMaterial;
use App\Models\ProductType;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Services\Amazon\AmazonSpapiApiException;
use App\Services\Amazon\AmazonSpapiCatalogClient;
use App\Services\BarcodeAliasService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class SkusIndex extends Component
{
    use HasEnumLabels;
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $tenantId = '';

    public string $shopId = '';

    #[Url(except: 'active')]
    public string $status = 'active';

    public string $skuType = '';

    public string $productType = '';

    #[Url(as: 'view')]
    public string $view = 'detailed';

    #[Url(as: 'per_page', except: 15)]
    public int $perPage = 15;

    public array $selectedIds = [];

    public array $visibleSkuIds = [];

    public array $catalogDrafts = [];

    public array $logisticsDrafts = [];

    public string $defaultViewPreference = '0';

    public bool $viewSettingsOpen = false;

    public string $stockItemCodeDisplay = self::STOCK_ITEM_CODE_DISPLAY_SYSTEM;

    public ?int $managingStockItemId = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $stockImages = [];

    /** @var array<int, string> */
    public array $stockImageOrder = [];

    /** @var array<int, int> */
    public array $imageAssetOrder = [];

    public ?int $managingAliasSkuId = null;

    public string $aliasBarcode = '';

    public string $aliasBarcodeType = 'other';

    public string $aliasLabel = '';

    public ?int $editingAliasId = null;

    public array $aliasEdit = [
        'barcode' => '',
        'barcode_type' => 'other',
        'label' => '',
    ];

    private const VIEW_DETAILED = 'detailed';

    private const VIEW_CATALOG = 'catalog';

    private const VIEW_MARKETPLACE = 'marketplace';

    private const VIEW_LOGISTICS = 'logistics';

    private const PER_PAGE_OPTIONS = [15, 30, 50, 100];

    private const STOCK_ITEM_CODE_DISPLAY_SYSTEM = 'system';

    private const STOCK_ITEM_CODE_DISPLAY_TENANT = 'tenant';

    private const STOCK_ITEM_CODE_DISPLAY_BOTH = 'both';

    private const STOCK_ITEM_CODE_DISPLAY_OPTIONS = [
        self::STOCK_ITEM_CODE_DISPLAY_SYSTEM,
        self::STOCK_ITEM_CODE_DISPLAY_TENANT,
        self::STOCK_ITEM_CODE_DISPLAY_BOTH,
    ];

    private const STOCK_IMAGE_TYPE = 'product';

    private const MAX_STOCK_IMAGE_SIDE = 2000;

    public function mount(): void
    {
        $queryView = request()->query('view');
        $savedView = Auth::user()?->preference('skus_view');

        $this->view = match (true) {
            is_string($queryView) && $this->isAllowedView($queryView) => $queryView,
            is_string($savedView) && $this->isAllowedView($savedView) => $savedView,
            default => self::VIEW_DETAILED,
        };

        $this->stockItemCodeDisplay = $this->stockItemCodeDisplayPreference();
        $this->syncDefaultViewPreference();
    }

    public function updatedView(): void
    {
        if (! $this->isAllowedView($this->view)) {
            $this->view = self::VIEW_DETAILED;
        }

        $this->syncDefaultViewPreference();
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->shopId = '';
        $this->resetPage();
    }

    public function updatedShopId(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        if (! $this->isAllowedStatus($this->status)) {
            $this->status = 'active';
        }

        $this->resetPage();
    }

    public function updatedSkuType(): void
    {
        $this->resetPage();
    }

    public function updatedProductType(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizePerPage($this->perPage);
        $this->resetPage();
    }

    public function switchView(string $view): void
    {
        $this->view = $this->isAllowedView($view) ? $view : self::VIEW_DETAILED;
        $this->syncDefaultViewPreference();
        $this->resetPage();
    }

    public function openViewSettings(): void
    {
        $this->stockItemCodeDisplay = $this->stockItemCodeDisplayPreference();
        $this->syncDefaultViewPreference();
        $this->viewSettingsOpen = true;
    }

    public function closeViewSettings(): void
    {
        $this->viewSettingsOpen = false;
    }

    public function saveViewSettings(): void
    {
        $data = validator([
            'stock_item_code_display' => $this->stockItemCodeDisplay,
            'default_view_preference' => $this->defaultViewPreference,
        ], [
            'stock_item_code_display' => ['required', Rule::in(self::STOCK_ITEM_CODE_DISPLAY_OPTIONS)],
            'default_view_preference' => ['required', Rule::in(['0', '1'])],
        ])->validate();

        $this->stockItemCodeDisplay = $data['stock_item_code_display'];
        $this->defaultViewPreference = $data['default_view_preference'];

        $user = Auth::user();

        if ($user) {
            $user->setPreference('stock_item_code_display', $this->stockItemCodeDisplay);
            if ($this->defaultViewPreference === '1' && $this->isAllowedView($this->view)) {
                $user->setPreference('skus_view', $this->view);
            } else {
                $user->forgetPreference('skus_view');
            }
            $this->flashStatus(__('skus.view_settings_saved'));
        }

        $this->viewSettingsOpen = false;
    }

    public function saveLogisticsField(int $skuId, string $field): void
    {
        $sku = $this->scopedSkuQuery()
            ->with('stockItem')
            ->find($skuId);

        if (! $sku) {
            abort(404);
        }

        if (! array_key_exists($field, $this->logisticsDrafts[$skuId] ?? [])) {
            return;
        }

        $value = $this->logisticsDrafts[$skuId][$field];

        if ($field === 'localized_name') {
            $this->saveStockItemField($sku, $field, $value);

            return;
        }

        if (in_array($field, ['short_name', 'weight_value', 'length_value', 'width_value', 'height_value'], true)) {
            $this->saveStockItemField($sku, $field, $value);

            return;
        }

        if ($field === 'default_packaging_material_id') {
            validator([$field => $value === '' ? null : $value], [
                $field => ['nullable', Rule::exists('packaging_materials', 'id')],
            ])->validate();

            $sku->update([$field => $this->nullableId((string) $value)]);
            $this->flashStatus(__('skus.inline_saved'));

            return;
        }

        if ($field === 'default_shipping_method_id') {
            $this->saveDefaultShippingMethod($sku, (string) $value);
        }
    }

    public function saveCatalogField(int $skuId, string $field): void
    {
        if ($field !== 'product_type') {
            return;
        }

        $sku = $this->scopedSkuQuery()
            ->with('stockItem')
            ->find($skuId);

        if (! $sku) {
            abort(404);
        }

        if (! array_key_exists($field, $this->catalogDrafts[$skuId] ?? [])) {
            return;
        }

        if (! $sku->stockItem || (int) $sku->stockItem->tenant_id !== (int) $sku->tenant_id || ! $this->tenantIsVisible((int) $sku->stockItem->tenant_id)) {
            abort(404);
        }

        $value = (string) $this->catalogDrafts[$skuId][$field];

        validator([$field => $value], [
            $field => ['required', 'string', Rule::exists('product_types', 'slug')],
        ])->validate();

        $sku->stockItem->update([$field => $value]);
        $this->flashStatus(__('skus.inline_saved'));
    }

    public function openImagePanel(int $stockItemId): void
    {
        $stockItem = $this->scopedStockItemQuery()->find($stockItemId);

        if (! $stockItem) {
            abort(404);
        }

        $this->resetValidation();
        $this->resetImageForm();
        $this->managingStockItemId = $stockItem->id;
        $this->syncImageAssetState($stockItem);
    }

    public function closeImagePanel(): void
    {
        $this->resetValidation();
        $this->resetImageForm();
        $this->managingStockItemId = null;
        $this->imageAssetOrder = [];
    }

    public function openAliasPanel(int $skuId): void
    {
        $sku = $this->scopedSkuQuery()
            ->with('stockItem')
            ->find($skuId);

        if (! $sku) {
            abort(404);
        }

        $this->resetValidation();
        $this->resetAliasForm();
        $this->managingAliasSkuId = $sku->id;
    }

    public function closeAliasPanel(): void
    {
        $this->resetValidation();
        $this->resetAliasForm();
        $this->managingAliasSkuId = null;
    }

    public function createBarcodeAlias(): void
    {
        $sku = $this->managedAliasSku();
        $target = $this->aliasBarcodeType === 'platform_label'
            ? BarcodeAlias::MODEL_TYPE_SKU
            : BarcodeAlias::MODEL_TYPE_STOCK_ITEM;

        if ($target === BarcodeAlias::MODEL_TYPE_STOCK_ITEM && ! $sku->stockItem) {
            throw ValidationException::withMessages(['aliasBarcodeType' => __('skus.missing_stock_item')]);
        }

        $modelId = $target === BarcodeAlias::MODEL_TYPE_STOCK_ITEM
            ? (int) $sku->stockItem->id
            : (int) $sku->id;
        $normalized = BarcodeAlias::normalize($this->aliasBarcode);

        validator([
            'aliasBarcode' => $this->aliasBarcode,
            'aliasBarcodeType' => $this->aliasBarcodeType,
            'aliasLabel' => $this->aliasLabel,
            'normalized_barcode' => $normalized,
        ], [
            'aliasBarcode' => ['required', 'string', 'max:255'],
            'aliasBarcodeType' => ['required', Rule::in(BarcodeAlias::BARCODE_TYPES)],
            'aliasLabel' => ['nullable', 'string', 'max:255'],
            'normalized_barcode' => ['required'],
        ])->validate();

        try {
            $barcodeAliases = app(BarcodeAliasService::class);
            $barcodeAliases->createManualAlias(
                tenantId: (int) $sku->tenant_id,
                modelType: $target,
                modelId: $modelId,
                barcode: $this->aliasBarcode,
                barcodeType: $this->aliasBarcodeType,
                label: $this->nullableString($this->aliasLabel),
                isActive: true,
            );

            if ($target === BarcodeAlias::MODEL_TYPE_SKU) {
                $barcodeAliases->syncSkuPlatformLabelMirror($sku);
            }
        } catch (AliasCollisionException $exception) {
            throw ValidationException::withMessages(['normalized_barcode' => $exception->getMessage()]);
        }

        $this->resetAliasForm();
        $this->flashStatus(__('skus.alias_created'));
    }

    public function deactivateSku(int $skuId): void
    {
        $sku = $this->skuForAction($skuId);

        $sku->update(['status' => 'inactive']);

        $this->flashStatus(__('skus.deactivated'));
    }

    public function reactivateSku(int $skuId): void
    {
        $sku = $this->skuForAction($skuId);

        $sku->update(['status' => 'active']);

        $this->flashStatus(__('skus.reactivated'));
    }

    public function deleteSku(int $skuId): void
    {
        $sku = $this->skuForAction($skuId);

        if (! $sku->canBeDeleted()) {
            $this->flashError(__('skus.delete_blocked_deactivate_instead'));

            return;
        }

        try {
            DB::transaction(function () use ($sku): void {
                $sku->deleteOwnedBarcodeAliases();
                $sku->delete();
            });
        } catch (QueryException) {
            $this->flashError(__('skus.delete_blocked_deactivate_instead'));

            return;
        }

        $this->flashStatus(__('skus.deleted'));
    }

    public function editSelectedSku()
    {
        $selectedIds = $this->normalizedSelectedIds();

        if (count($selectedIds) !== 1) {
            $this->flashError(__('skus.select_one_to_edit'));

            return null;
        }

        $sku = $this->skuForAction($selectedIds[0]);

        return redirect()->route('skus.edit', $sku);
    }

    public function bulkDeactivate(): void
    {
        $selectedIds = $this->normalizedSelectedIds();

        if ($selectedIds === []) {
            return;
        }

        $updated = $this->scopedSkuQuery()
            ->whereIn('id', $selectedIds)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        $this->clearSelection();
        $this->flashStatus(__('skus.bulk_deactivated', ['count' => $updated]));
    }

    public function bulkReactivate(): void
    {
        $selectedIds = $this->normalizedSelectedIds();

        if ($selectedIds === []) {
            return;
        }

        $updated = $this->scopedSkuQuery()
            ->whereIn('id', $selectedIds)
            ->where('status', 'inactive')
            ->update(['status' => 'active']);

        $this->clearSelection();
        $this->flashStatus(__('skus.bulk_reactivated', ['count' => $updated]));
    }

    public function bulkDelete(): void
    {
        $selectedIds = $this->normalizedSelectedIds();

        if ($selectedIds === []) {
            return;
        }

        $deleted = 0;
        $blocked = 0;

        $skus = Sku::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->whereIn('id', $selectedIds)
            ->get();

        foreach ($skus as $sku) {
            if (! $sku->canBeDeleted()) {
                $blocked++;

                continue;
            }

            try {
                DB::transaction(function () use ($sku): void {
                    $sku->deleteOwnedBarcodeAliases();
                    $sku->delete();
                });

                $deleted++;
            } catch (QueryException) {
                $blocked++;
            }
        }

        $this->clearSelection();

        if ($deleted > 0 && $blocked > 0) {
            $this->flashError(__('skus.bulk_deleted_with_blocked', ['deleted' => $deleted, 'blocked' => $blocked]));

            return;
        }

        if ($deleted > 0) {
            $this->flashStatus(__('skus.bulk_deleted', ['count' => $deleted]));

            return;
        }

        $this->flashError(__('skus.delete_blocked_deactivate_instead'));
    }

    public function deactivateBarcodeAlias(int $aliasId): void
    {
        [$sku, $alias] = $this->managedBarcodeAlias($aliasId);

        if (! $this->canManageBarcodeAlias($alias)) {
            abort(403);
        }

        $alias->update(['is_active' => false]);

        if ($alias->model_type === BarcodeAlias::MODEL_TYPE_SKU && $alias->barcode_type === 'platform_label') {
            app(BarcodeAliasService::class)->syncSkuPlatformLabelMirror($sku);
        }

        $this->flashStatus(__('skus.alias_deactivated'));
    }

    public function reactivateBarcodeAlias(int $aliasId): void
    {
        [, $alias] = $this->managedBarcodeAlias($aliasId);

        if (! $this->canManageBarcodeAlias($alias)) {
            abort(403);
        }

        $alias->update(['is_active' => true]);

        $this->flashStatus(__('skus.alias_reactivated'));
    }

    public function editBarcodeAlias(int $aliasId): void
    {
        [, $alias] = $this->managedBarcodeAlias($aliasId);

        if (! $this->canManageBarcodeAlias($alias)) {
            abort(403);
        }

        $this->editingAliasId = $alias->id;
        $this->aliasEdit = [
            'barcode' => $alias->barcode,
            'barcode_type' => $alias->barcode_type,
            'label' => $alias->label ?? '',
        ];
    }

    public function cancelEditBarcodeAlias(): void
    {
        $this->resetAliasEditForm();
    }

    public function saveBarcodeAlias(int $aliasId): void
    {
        [$sku, $alias] = $this->managedBarcodeAlias($aliasId);

        if (! $this->canManageBarcodeAlias($alias)) {
            abort(403);
        }

        $validated = validator($this->aliasEdit, [
            'barcode' => ['required', 'string', 'max:255'],
            'barcode_type' => ['required', Rule::in(BarcodeAlias::BARCODE_TYPES)],
            'label' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $normalized = BarcodeAlias::normalize($validated['barcode']);

        if ($normalized === '') {
            throw ValidationException::withMessages(['aliasEdit.barcode' => __('skus.alias_barcode_required')]);
        }

        $conflict = BarcodeAlias::query()
            ->where('tenant_id', $alias->tenant_id)
            ->where('normalized_barcode', $normalized)
            ->whereKeyNot($alias->id)
            ->first();

        if ($conflict) {
            $message = $conflict->model_type === $alias->model_type && (int) $conflict->model_id === (int) $alias->model_id
                ? __('skus.alias_duplicate_same_product')
                : __('skus.alias_conflict_other_product');

            throw ValidationException::withMessages(['aliasEdit.barcode' => $message]);
        }

        $oldType = $alias->barcode_type;

        $alias->update([
            'barcode' => trim($validated['barcode']),
            'normalized_barcode' => $normalized,
            'barcode_type' => $validated['barcode_type'],
            'label' => $this->nullableString((string) ($validated['label'] ?? '')),
        ]);

        if ($alias->model_type === BarcodeAlias::MODEL_TYPE_SKU && ($oldType === 'platform_label' || $alias->barcode_type === 'platform_label')) {
            app(BarcodeAliasService::class)->syncSkuPlatformLabelMirror($sku);
        }

        $this->resetAliasEditForm();
        $this->flashStatus(__('skus.alias_updated'));
    }

    public function uploadStockImage(): void
    {
        $stockItem = $this->managedStockItem();
        $imageCount = $stockItem->mediaAssets()->count();

        $this->validate([
            'stockImages' => ['required', 'array', 'min:1'],
            'stockImages.*' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'stockImages.required' => __('skus.image_file_required'),
            'stockImages.min' => __('skus.image_file_required'),
            'stockImages.*.image' => __('skus.image_file_must_be_image'),
            'stockImages.*.mimetypes' => __('skus.image_file_must_be_image'),
            'stockImages.*.mimes' => __('skus.image_file_must_be_image'),
        ]);

        $images = $this->orderedStockImages();
        $uploadCount = count($images);

        if ($uploadCount < 1) {
            throw ValidationException::withMessages([
                'stockImages' => __('skus.image_file_required'),
            ]);
        }

        if ($imageCount >= 10 || $imageCount + $uploadCount > 10) {
            throw ValidationException::withMessages([
                'stockImages' => __('skus.image_limit_reached'),
            ]);
        }

        $uploadedAssets = [];
        $baseSortOrder = $this->nextImageSortOrder($stockItem->id);
        $baseFileNumber = $this->nextImageFileNumber($stockItem->id);

        foreach ($images as $index => $image) {
            $sortOrder = $baseSortOrder + $index;
            $fileName = $this->stockImageFileName($stockItem, $baseFileNumber + $index, $image);
            $path = 'product-images/tenant-'.$stockItem->tenant_id.'/stock-items/'.$stockItem->id.'/'.$fileName;
            $shouldBePrimary = $imageCount === 0 && $index === 0;
            $storedImage = $this->storeStockImage($image, $path);

            $uploadedAssets[] = DB::transaction(function () use ($stockItem, $path, $fileName, $storedImage, $sortOrder, $shouldBePrimary) {
                if ($shouldBePrimary) {
                    $this->clearPrimaryImages($stockItem->id);
                }

                return MediaAsset::create([
                    'tenant_id' => $stockItem->tenant_id,
                    'model_type' => MediaAsset::MODEL_TYPE_STOCK_ITEM,
                    'model_id' => $stockItem->id,
                    'type' => self::STOCK_IMAGE_TYPE,
                    'disk' => 'public',
                    'path' => $path,
                    'file_name' => $fileName,
                    'mime_type' => $storedImage['mime_type'],
                    'size_bytes' => $storedImage['size_bytes'],
                    'width' => $storedImage['width'],
                    'height' => $storedImage['height'],
                    'sort_order' => $sortOrder,
                    'is_primary' => $shouldBePrimary,
                    'uploaded_by_user_id' => Auth::id(),
                ]);
            });
        }

        if ($imageCount === 0) {
            $this->syncFirstImageAsPrimary($stockItem->id);
        }

        foreach ($uploadedAssets as $asset) {
            activity('stock_item')
                ->performedOn($stockItem)
                ->causedBy(Auth::user())
                ->withProperties([
                    'media_asset_id' => $asset->id,
                    'is_primary' => $asset->is_primary,
                ])
                ->log('image uploaded');
        }

        $this->resetImageForm();
        $this->dispatch('stock-images-reset');
        $stockItem = $stockItem->refresh();
        $this->syncImageAssetState($stockItem);
        $this->dispatch('stock-images-synced', images: $this->stockImageCards($stockItem));
        $this->flashStatus(trans_choice('skus.images_uploaded', count($uploadedAssets), ['count' => count($uploadedAssets)]));
    }

    public function saveStockImages(): void
    {
        $stockItem = $this->managedStockItem();
        $hasExistingImages = $stockItem->mediaAssets()->exists();

        if ($this->stockImages !== []) {
            if ($hasExistingImages) {
                $this->saveImageArrangement(flash: false);
            }

            $this->uploadStockImage();

            return;
        }

        $this->saveImageArrangement();
    }

    public function deleteImage(int $mediaAssetId): void
    {
        $asset = $this->scopedMediaAssetQuery()->find($mediaAssetId);

        if (! $asset) {
            abort(404);
        }

        $stockItem = $this->scopedStockItemQuery()->find($asset->model_id);

        if (! $stockItem) {
            abort(404);
        }

        $disk = $asset->disk;
        $path = $asset->path;
        $wasPrimary = $asset->is_primary;

        DB::transaction(function () use ($asset, $stockItem, $wasPrimary): void {
            $asset->delete();

            if ($wasPrimary) {
                $this->syncFirstImageAsPrimary($stockItem->id);
            }
        });

        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $exception) {
            Log::warning('Product image file deletion failed.', [
                'disk' => $disk,
                'path' => $path,
                'media_asset_id' => $mediaAssetId,
                'exception' => $exception->getMessage(),
            ]);
        }

        activity('stock_item')
            ->performedOn($stockItem)
            ->causedBy(Auth::user())
            ->withProperties(['media_asset_id' => $mediaAssetId])
            ->log('image deleted');

        $stockItem = $stockItem->refresh();
        $this->syncImageAssetState($stockItem);
        $this->dispatch('stock-images-synced', images: $this->stockImageCards($stockItem));
    }

    public function importAmazonImage(int $skuId): void
    {
        $sku = $this->scopedSkuQuery()
            ->with(['shop.amazonSpapiConnection', 'stockItem.primaryImage'])
            ->find($skuId);

        if (! $sku || ! $sku->stockItem || ! $this->tenantIsVisible((int) $sku->stockItem->tenant_id)) {
            abort(404);
        }

        if ($sku->shop?->platform !== 'amazon') {
            $this->flashError(__('skus.amazon_image_requires_amazon_shop'));

            return;
        }

        $asin = trim((string) $sku->platform_product_id);

        if ($asin === '') {
            $this->flashError(__('skus.amazon_image_requires_asin'));

            return;
        }

        $connection = $sku->shop->amazonSpapiConnection;

        if (! $connection) {
            $this->flashError(__('skus.amazon_image_requires_connection'));

            return;
        }

        $stockItem = $sku->stockItem;
        $existingAmazon = $stockItem->mediaAssets()
            ->where('type', 'amazon')
            ->first();
        $imageCount = $stockItem->mediaAssets()->count();

        if (! $existingAmazon && $imageCount >= 10) {
            $this->flashError(__('skus.image_limit_reached'));

            return;
        }

        try {
            $imageUrl = app(AmazonSpapiCatalogClient::class)->getMainImageUrl($connection, $asin, $connection->marketplace_id);
        } catch (AmazonSpapiApiException $exception) {
            $this->flashError(__('skus.amazon_image_api_failed', ['message' => $exception->getMessage()]));

            return;
        }

        if (! $imageUrl) {
            $this->flashError(__('skus.amazon_image_not_found'));

            return;
        }

        if ($existingAmazon && $existingAmazon->original_url === $imageUrl) {
            $this->flashStatus(__('skus.amazon_image_already_imported'));

            return;
        }

        try {
            $download = $this->downloadAmazonImage($imageUrl);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $this->flashError(__('skus.amazon_image_download_failed'));

            return;
        }

        $fileName = Str::uuid()->toString().'.'.$download['extension'];
        $path = 'product-images/tenant-'.$stockItem->tenant_id.'/stock-items/'.$stockItem->id.'/'.$fileName;
        $shouldBePrimary = ! $stockItem->mediaAssets()->where('is_primary', true)->exists();

        Storage::disk('public')->put($path, $download['bytes']);

        $asset = DB::transaction(function () use ($stockItem, $existingAmazon, $imageUrl, $download, $path, $shouldBePrimary) {
            if ($existingAmazon) {
                $existingAmazon->delete();
            }

            if ($shouldBePrimary) {
                $this->clearPrimaryImages($stockItem->id);
            }

            return MediaAsset::create([
                'tenant_id' => $stockItem->tenant_id,
                'model_type' => MediaAsset::MODEL_TYPE_STOCK_ITEM,
                'model_id' => $stockItem->id,
                'type' => 'amazon',
                'disk' => 'public',
                'path' => $path,
                'original_url' => $imageUrl,
                'file_name' => basename(parse_url($imageUrl, PHP_URL_PATH) ?: $path),
                'mime_type' => $download['mime_type'],
                'size_bytes' => strlen($download['bytes']),
                'width' => $download['width'],
                'height' => $download['height'],
                'sort_order' => $this->nextImageSortOrder($stockItem->id),
                'is_primary' => $shouldBePrimary,
                'uploaded_by_user_id' => Auth::id(),
            ]);
        });

        if ($existingAmazon) {
            Storage::disk($existingAmazon->disk)->delete($existingAmazon->path);
        }

        activity('stock_item')
            ->performedOn($stockItem)
            ->causedBy(Auth::user())
            ->withProperties([
                'media_asset_id' => $asset->id,
                'asin' => $asin,
                'original_url' => $imageUrl,
            ])
            ->log('amazon image imported');

        $this->flashStatus(__('skus.amazon_image_imported'));
    }

    public function render()
    {
        if (! $this->isAllowedView($this->view)) {
            $this->view = self::VIEW_DETAILED;
        }

        if (! $this->isAllowedStatus($this->status)) {
            $this->status = 'active';
        }

        $this->perPage = $this->normalizePerPage($this->perPage);

        $skus = $this->skus();
        $this->visibleSkuIds = $skus->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->prepareCatalogDrafts($skus->getCollection());
        $this->prepareLogisticsDrafts($skus->getCollection());

        return view('livewire.skus-index', [
            'skus' => $skus,
            'tenants' => $this->tenantOptions(),
            'shops' => $this->shopOptions(),
            'statuses' => $this->statusOptions(),
            'skuTypes' => $this->skuTypeOptions(),
            'productTypes' => $this->productTypeOptions(),
            'showTenantFilter' => $this->isInternalUser(),
            'views' => $this->viewOptions(),
            'flatColumns' => $this->flatColumns(),
            'currentColumnCount' => $this->currentColumnCount(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'paginationPages' => $this->paginationPages($skus),
            'visibleSkuIds' => $this->visibleSkuIds,
            'packagingMaterials' => $this->packagingMaterialOptions(),
            'shippingMethods' => $this->shippingMethodOptions($skus->getCollection()),
            'canSaveDefaultView' => Auth::check(),
            'managedStockItem' => $this->managedStockItemForView(),
            'managedAliasSku' => $this->managedAliasSkuForView(),
        ])->layout('inventory', [
            'title' => __('skus.page_title'),
            'subtitle' => __('skus.page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function skus()
    {
        return $this->baseQuery()
            ->with([
                'tenant:id,code,name',
                'shop:id,tenant_id,code,name,platform,marketplace',
                'barcodeAliases',
                'stockItem' => fn ($query) => $query->select([
                    'id',
                    'tenant_id',
                    'code',
                    'tenant_item_code',
                    ...StockItem::DISPLAY_NAME_COLUMNS,
                    'brand',
                    'model_number',
                    'variation_code',
                    'color',
                    'size',
                    'barcode',
                    'product_type',
                    'weight_value',
                    'weight_unit',
                    'length_value',
                    'width_value',
                    'height_value',
                    'dimension_unit',
                ]),
                'stockItem.barcodeAliases',
                'stockItem.primaryImage:id,tenant_id,model_type,model_id,type,disk,path,file_name,is_primary,sort_order',
                'bundleComponents' => fn ($query) => $query->with('componentStockItem:id,tenant_id,code,name')->orderBy('id'),
                'defaultPackagingMaterial:id,code,name,type',
                'defaultShippingMethod:id,carrier_id,code,name,status',
                'defaultShippingMethod.carrier:id,code,name',
            ])
            ->latest('id')
            ->paginate($this->perPage);
    }

    private function normalizePerPage(int $perPage): int
    {
        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 15;
    }

    public function bundleComposition(Sku $sku, int $limit = 2): string
    {
        $components = $sku->bundleComponents->take($limit)->map(function ($component) {
            $code = $component->componentStockItem?->code ?? __('skus.unknown_stock_item');

            return __('skus.bundle_component', ['code' => $code, 'qty' => number_format($component->quantity)]);
        });

        if ($components->isEmpty()) {
            return __('skus.no_components_configured');
        }

        $composition = $components->implode(' + ');
        $hiddenCount = max(0, $sku->bundleComponents->count() - $limit);

        return $hiddenCount > 0
            ? __('skus.bundle_more', ['composition' => $composition, 'count' => $hiddenCount])
            : $composition;
    }

    private function paginationPages($paginator): array
    {
        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();

        return collect([1, $currentPage - 2, $currentPage - 1, $currentPage, $currentPage + 1, $currentPage + 2, $lastPage])
            ->filter(fn ($page) => $page >= 1 && $page <= $lastPage)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function skuTypeLabel(string $type): string
    {
        return $this->enumLabel('sku_types', $type);
    }

    public function productTypeLabel(string $type): string
    {
        static $map = null;
        $locale = app()->getLocale();
        $map ??= ProductType::all()->mapWithKeys(
            fn ($t) => [$t->slug => $t->translations[$locale] ?? $t->name]
        )->all();

        return $map[$type] ?? $type;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => __('skus.status_active'),
            'inactive' => __('skus.status_inactive'),
            'all' => __('skus.status_all'),
            default => $this->enumLabel('statuses', $status),
        };
    }

    public function flatCellValue(Sku $sku, string $key): string
    {
        $value = match ($key) {
            'sku' => $sku->sku,
            'name' => $sku->name,
            'brand' => $sku->stockItem?->brand,
            'variation_code' => $sku->stockItem?->variation_code,
            'barcode' => $this->stockItemPrimaryBarcode($sku) ?? $sku->stockItem?->barcode,
            'size' => $sku->stockItem?->size,
            'color' => $sku->stockItem?->color,
            'shop_code' => $sku->shop?->code,
            'type' => $this->skuTypeLabel($sku->sku_type),
            'status' => $this->statusLabel($sku->status),
            'platform_product_id' => $sku->platform_product_id,
            'platform_label_code' => $sku->platform_label_code,
            default => null,
        };

        return filled($value) ? (string) $value : '-';
    }

    public function logisticsStockItemName(Sku $sku): string
    {
        $stockItem = $sku->stockItem;

        if (! $stockItem) {
            return '';
        }

        $column = $this->currentStockItemNameColumn();

        return (string) ($stockItem->{$column} ?? '');
    }

    public function viewOptions(): array
    {
        return [
            self::VIEW_DETAILED => __('skus.view_detailed'),
            self::VIEW_CATALOG => __('skus.view_catalog'),
            self::VIEW_MARKETPLACE => __('skus.view_marketplace'),
            self::VIEW_LOGISTICS => __('skus.view_logistics'),
        ];
    }

    private function baseQuery(): Builder
    {
        return $this->scopedSkuQuery()
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->when($this->shopId !== '', fn ($query) => $query->where('shop_id', $this->shopId))
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->when($this->skuType !== '', fn ($query) => $query->where('sku_type', $this->skuType))
            ->when($this->productType !== '', fn ($query) => $query->whereHas('stockItem', fn ($query) => $query->where('product_type', $this->productType)))
            ->when($this->search !== '', function ($query) {
                $search = '%'.$this->search.'%';
                $normalized = BarcodeAlias::normalize($this->search);
                $normalizedSearch = $normalized === '' ? null : '%'.$normalized.'%';

                $query->where(function ($query) use ($search, $normalizedSearch) {
                    $query
                        ->where('sku', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('platform_sku', 'like', $search)
                        ->orWhere('platform_product_id', 'like', $search)
                        ->orWhere('platform_variant_id', 'like', $search)
                        ->orWhere('platform_label_code', 'like', $search)
                        ->orWhereHas('barcodeAliases', function ($query) use ($search, $normalizedSearch): void {
                            $query
                                ->where('is_active', true)
                                ->where(function ($query) use ($search, $normalizedSearch): void {
                                    $query->where('barcode', 'like', $search)
                                        ->when($normalizedSearch !== null, fn ($query) => $query->orWhere('normalized_barcode', 'like', $normalizedSearch));
                                });
                        })
                        ->orWhereHas('stockItem', function ($query) use ($search) {
                            $query
                                ->where('code', 'like', $search)
                                ->orWhere('tenant_item_code', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhere('barcode', 'like', $search);
                        })
                        ->orWhereHas('stockItem.barcodeAliases', function ($query) use ($search, $normalizedSearch): void {
                            $query
                                ->where('is_active', true)
                                ->where(function ($query) use ($search, $normalizedSearch): void {
                                    $query->where('barcode', 'like', $search)
                                        ->when($normalizedSearch !== null, fn ($query) => $query->orWhere('normalized_barcode', 'like', $normalizedSearch));
                                });
                        });
                });
            });
    }

    private function scopedSkuQuery(): Builder
    {
        return Sku::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()));
    }

    public function stockItemDisplayCode(StockItem $stockItem): string
    {
        return $stockItem->displayCode($this->stockItemCodeDisplay);
    }

    public function stockItemCodeDisplayOptions(): array
    {
        return [
            self::STOCK_ITEM_CODE_DISPLAY_SYSTEM => __('skus.stock_item_code_display_system'),
            self::STOCK_ITEM_CODE_DISPLAY_TENANT => __('skus.stock_item_code_display_tenant'),
            self::STOCK_ITEM_CODE_DISPLAY_BOTH => __('skus.stock_item_code_display_both'),
        ];
    }

    private function tenantOptions(): Collection
    {
        return Tenant::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('id', $this->visibleTenantIds()))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'code', 'name']);
    }

    private function statusOptions(): Collection
    {
        return collect(['active', 'inactive', 'all']);
    }

    private function skuTypeOptions(): Collection
    {
        return Sku::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->select('sku_type')
            ->distinct()
            ->orderBy('sku_type')
            ->pluck('sku_type');
    }

    private function productTypeOptions(): Collection
    {
        return ProductType::orderBy('sort_order')->orderBy('name')->get(['slug', 'name']);
    }

    private function packagingMaterialOptions(): Collection
    {
        return PackagingMaterial::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type']);
    }

    private function shippingMethodOptions(Collection $skus): Collection
    {
        $currentIds = $skus
            ->pluck('default_shipping_method_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ShippingMethod::query()
            ->where(function ($query) use ($currentIds) {
                $query->where('shipping_methods.status', 'active')
                    ->when($currentIds !== [], fn ($query) => $query->orWhereIn('shipping_methods.id', $currentIds));
            })
            ->with('carrier:id,code,name')
            ->ordered()
            ->get(['shipping_methods.id', 'shipping_methods.carrier_id', 'shipping_methods.code', 'shipping_methods.name', 'shipping_methods.status']);
    }

    private function prepareLogisticsDrafts(Collection $skus): void
    {
        foreach ($skus as $sku) {
            $this->logisticsDrafts[$sku->id] ??= [
                'localized_name' => $this->logisticsStockItemName($sku),
                'short_name' => (string) ($sku->stockItem?->short_name ?? ''),
                'weight_value' => $this->logisticsDraftValue('weight_value', $sku->stockItem?->weight_value),
                'length_value' => $this->logisticsDraftValue('length_value', $sku->stockItem?->length_value),
                'width_value' => $this->logisticsDraftValue('width_value', $sku->stockItem?->width_value),
                'height_value' => $this->logisticsDraftValue('height_value', $sku->stockItem?->height_value),
                'default_packaging_material_id' => $sku->default_packaging_material_id ? (string) $sku->default_packaging_material_id : '',
                'default_shipping_method_id' => $sku->default_shipping_method_id ? (string) $sku->default_shipping_method_id : '',
            ];
        }
    }

    private function prepareCatalogDrafts(Collection $skus): void
    {
        foreach ($skus as $sku) {
            $this->catalogDrafts[$sku->id] ??= [
                'product_type' => (string) ($sku->stockItem?->product_type ?? 'normal'),
            ];
        }
    }

    private function saveStockItemField(Sku $sku, string $field, mixed $value): void
    {
        if (! $sku->stockItem) {
            return;
        }

        if ((int) $sku->stockItem->tenant_id !== (int) $sku->tenant_id || ! $this->tenantIsVisible((int) $sku->stockItem->tenant_id)) {
            abort(404);
        }

        $rules = [
            'localized_name' => ['nullable', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'weight_value' => ['nullable', 'numeric', 'min:0'],
            'length_value' => ['nullable', 'numeric', 'min:0'],
            'width_value' => ['nullable', 'numeric', 'min:0'],
            'height_value' => ['nullable', 'numeric', 'min:0'],
        ];

        if (! isset($rules[$field])) {
            return;
        }

        validator([$field => $value === '' ? null : $value], [$field => $rules[$field]])->validate();

        if ($field === 'localized_name') {
            $column = $this->currentStockItemNameColumn();

            $sku->stockItem->update([
                $column => $this->nullableString((string) $value),
            ]);

            $sku->stockItem->refresh();
            $this->refreshSharedStockItemDrafts($sku, $field, $sku->stockItem->{$column});

            $this->flashStatus(__('skus.inline_saved'));

            return;
        }

        $sku->stockItem->update([
            $field => $field === 'short_name' ? $this->nullableString((string) $value) : $this->nullableDecimal((string) $value),
        ]);

        $sku->stockItem->refresh();
        $this->refreshSharedStockItemDrafts($sku, $field, $sku->stockItem->{$field});

        $this->flashStatus(__('skus.inline_saved'));
    }

    private function refreshSharedStockItemDrafts(Sku $sku, string $field, mixed $value): void
    {
        if (! $sku->stock_item_id) {
            return;
        }

        $draftValue = $this->logisticsDraftValue($field, $value);
        $visibleSkuIds = $this->scopedSkuQuery()
            ->where('stock_item_id', $sku->stock_item_id)
            ->pluck('id');

        foreach ($visibleSkuIds as $visibleSkuId) {
            if (array_key_exists($visibleSkuId, $this->logisticsDrafts)) {
                $this->logisticsDrafts[$visibleSkuId][$field] = $draftValue;

                continue;
            }

            $stringKey = (string) $visibleSkuId;

            if (array_key_exists($stringKey, $this->logisticsDrafts)) {
                $this->logisticsDrafts[$stringKey][$field] = $draftValue;
            }
        }
    }

    private function currentStockItemNameColumn(): string
    {
        return match (app()->getLocale()) {
            'ja' => 'name_ja',
            'zh_TW' => 'name_zh_tw',
            'zh_CN' => 'name_zh_cn',
            default => 'name_en',
        };
    }

    private function saveDefaultShippingMethod(Sku $sku, string $value): void
    {
        $methodId = $this->nullableId($value);

        if ($methodId === null) {
            $sku->update(['default_shipping_method_id' => null]);
            $this->flashStatus(__('skus.inline_saved'));

            return;
        }

        if ($sku->default_shipping_method_id === $methodId) {
            return;
        }

        $exists = ShippingMethod::query()
            ->whereKey($methodId)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['default_shipping_method_id' => __('skus.invalid_shipping_method')]);
        }

        $sku->update(['default_shipping_method_id' => $methodId]);
        $this->flashStatus(__('skus.inline_saved'));
    }

    private function flatColumns(): array
    {
        return match ($this->view) {
            self::VIEW_CATALOG => [
                'image' => __('skus.col_image'),
                'sku' => __('skus.col_sku'),
                'name' => __('skus.col_name'),
                'brand' => __('skus.col_brand'),
                'barcode' => __('skus.col_barcode'),
                'variation_code' => __('skus.col_variation_code'),
                'size' => __('skus.col_size'),
                'color' => __('skus.col_color'),
                'type' => __('skus.col_type'),
                'product_type' => __('skus.col_product_type'),
            ],
            self::VIEW_MARKETPLACE => [
                'sku' => __('skus.col_sku'),
                'name' => __('skus.col_name'),
                'platform_product_id' => __('skus.col_asin'),
                'platform_label_code' => __('skus.col_fnsku'),
                'shop_code' => __('skus.col_shop'),
            ],
            default => [],
        };
    }

    private function currentColumnCount(): int
    {
        return match ($this->view) {
            self::VIEW_CATALOG => 11,
            self::VIEW_MARKETPLACE => 6,
            self::VIEW_LOGISTICS => 12,
            default => 8,
        };
    }

    private function syncDefaultViewPreference(): void
    {
        $this->defaultViewPreference = Auth::user()?->preference('skus_view') === $this->view ? '1' : '0';
    }

    private function normalizeStockItemCodeDisplay(mixed $value): string
    {
        return is_string($value) && in_array($value, self::STOCK_ITEM_CODE_DISPLAY_OPTIONS, true)
            ? $value
            : self::STOCK_ITEM_CODE_DISPLAY_SYSTEM;
    }

    private function stockItemCodeDisplayPreference(): string
    {
        $user = Auth::user();
        $preference = $user?->preference('stock_item_code_display');

        if (is_string($preference)) {
            return $this->normalizeStockItemCodeDisplay($preference);
        }

        return $user?->preference('show_tenant_item_code', false)
            ? self::STOCK_ITEM_CODE_DISPLAY_BOTH
            : self::STOCK_ITEM_CODE_DISPLAY_SYSTEM;
    }

    private function isAllowedView(string $view): bool
    {
        return in_array($view, [self::VIEW_DETAILED, self::VIEW_CATALOG, self::VIEW_MARKETPLACE, self::VIEW_LOGISTICS], true);
    }

    private function isAllowedStatus(string $status): bool
    {
        return in_array($status, ['active', 'inactive', 'all'], true);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function tenantIsVisible(int $tenantId): bool
    {
        $visibleTenantIds = $this->visibleTenantIds();

        return $visibleTenantIds === null || in_array($tenantId, array_map('intval', $visibleTenantIds), true);
    }

    private function visibleTenantIds(): ?array
    {
        if ($this->isInternalUser()) {
            return null;
        }

        $user = Auth::user();

        if (! $user) {
            return [];
        }

        return $user->activeTenantIds();
    }

    /**
     * @return Builder<StockItem>
     */
    private function scopedStockItemQuery(): Builder
    {
        return StockItem::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()));
    }

    private function skuForAction(int $skuId): Sku
    {
        $sku = Sku::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->find($skuId);

        if (! $sku) {
            abort(404);
        }

        return $sku;
    }

    private function normalizedSelectedIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->selectedIds)));
    }

    private function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    /**
     * @return Builder<MediaAsset>
     */
    private function scopedMediaAssetQuery(): Builder
    {
        return MediaAsset::query()
            ->where('model_type', MediaAsset::MODEL_TYPE_STOCK_ITEM)
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()));
    }

    private function managedStockItem(): StockItem
    {
        if (! $this->managingStockItemId) {
            abort(404);
        }

        $stockItem = $this->scopedStockItemQuery()->find($this->managingStockItemId);

        if (! $stockItem) {
            abort(404);
        }

        return $stockItem;
    }

    private function managedStockItemForView(): ?StockItem
    {
        if (! $this->managingStockItemId) {
            return null;
        }

        return $this->scopedStockItemQuery()
            ->with('mediaAssets')
            ->find($this->managingStockItemId);
    }

    private function managedAliasSku(): Sku
    {
        if (! $this->managingAliasSkuId) {
            abort(404);
        }

        $sku = $this->scopedSkuQuery()
            ->with('stockItem')
            ->find($this->managingAliasSkuId);

        if (! $sku) {
            abort(404);
        }

        if ($sku->stockItem && ((int) $sku->stockItem->tenant_id !== (int) $sku->tenant_id || ! $this->tenantIsVisible((int) $sku->stockItem->tenant_id))) {
            abort(404);
        }

        return $sku;
    }

    private function managedAliasSkuForView(): ?Sku
    {
        if (! $this->managingAliasSkuId) {
            return null;
        }

        return $this->scopedSkuQuery()
            ->with(['stockItem.barcodeAliases', 'barcodeAliases'])
            ->find($this->managingAliasSkuId);
    }

    private function managedBarcodeAlias(int $aliasId): array
    {
        $sku = $this->managedAliasSku();
        $stockItemId = $sku->stockItem ? (int) $sku->stockItem->id : null;

        $alias = BarcodeAlias::query()
            ->where('tenant_id', $sku->tenant_id)
            ->where(function ($query) use ($sku, $stockItemId): void {
                $query
                    ->where(function ($query) use ($sku): void {
                        $query
                            ->where('model_type', BarcodeAlias::MODEL_TYPE_SKU)
                            ->where('model_id', $sku->id);
                    })
                    ->when($stockItemId !== null, fn ($query) => $query->orWhere(function ($query) use ($stockItemId): void {
                        $query
                            ->where('model_type', BarcodeAlias::MODEL_TYPE_STOCK_ITEM)
                            ->where('model_id', $stockItemId);
                    }));
            })
            ->find($aliasId);

        if (! $alias) {
            abort(404);
        }

        return [$sku, $alias];
    }

    private function canManageBarcodeAlias(BarcodeAlias $alias): bool
    {
        return $alias->source !== BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE;
    }

    private function clearPrimaryImages(int $stockItemId): void
    {
        MediaAsset::query()
            ->where('model_type', MediaAsset::MODEL_TYPE_STOCK_ITEM)
            ->where('model_id', $stockItemId)
            ->update(['is_primary' => false]);
    }

    private function syncFirstImageAsPrimary(int $stockItemId): void
    {
        $assets = MediaAsset::query()
            ->where('model_type', MediaAsset::MODEL_TYPE_STOCK_ITEM)
            ->where('model_id', $stockItemId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($assets as $index => $asset) {
            $asset->forceFill([
                'sort_order' => $index + 1,
                'is_primary' => $index === 0,
            ])->save();
        }
    }

    private function nextImageSortOrder(int $stockItemId): int
    {
        return ((int) MediaAsset::query()
            ->where('model_type', MediaAsset::MODEL_TYPE_STOCK_ITEM)
            ->where('model_id', $stockItemId)
            ->max('sort_order')) + 1;
    }

    private function nextImageFileNumber(int $stockItemId): int
    {
        return $this->nextImageSortOrder($stockItemId);
    }

    /**
     * @return array{mime_type: string|null, size_bytes: int|null, width: int|null, height: int|null}
     */
    private function storeStockImage(TemporaryUploadedFile $file, string $path): array
    {
        $size = @getimagesize($file->getRealPath());
        $width = $size[0] ?? null;
        $height = $size[1] ?? null;
        $mimeType = $size['mime'] ?? $file->getMimeType();

        if (! $width || ! $height || max($width, $height) <= self::MAX_STOCK_IMAGE_SIDE) {
            Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));

            return [
                'mime_type' => $mimeType,
                'size_bytes' => $file->getSize(),
                'width' => $width,
                'height' => $height,
            ];
        }

        $ratio = self::MAX_STOCK_IMAGE_SIDE / max($width, $height);
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));
        $resizedBytes = $this->resizeImageBytes($file->getRealPath(), $mimeType, $targetWidth, $targetHeight);

        if ($resizedBytes === null) {
            Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));

            return [
                'mime_type' => $mimeType,
                'size_bytes' => $file->getSize(),
                'width' => $width,
                'height' => $height,
            ];
        }

        Storage::disk('public')->put($path, $resizedBytes);

        return [
            'mime_type' => $mimeType,
            'size_bytes' => strlen($resizedBytes),
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    private function resizeImageBytes(string $path, ?string $mimeType, int $targetWidth, int $targetHeight): ?string
    {
        $source = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if (! $source) {
            return null;
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        }

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            imagesx($source),
            imagesy($source)
        );

        ob_start();
        $saved = match ($mimeType) {
            'image/jpeg' => imagejpeg($target, null, 85),
            'image/png' => imagepng($target, null, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($target, null, 85) : false,
            default => false,
        };
        $output = (string) ob_get_clean();
        $bytes = $saved ? $output : null;

        imagedestroy($target);
        imagedestroy($source);

        return $bytes;
    }

    private function resetImageForm(): void
    {
        $this->stockImages = [];
        $this->stockImageOrder = [];
    }

    private function saveImageArrangement(bool $flash = true): void
    {
        $stockItem = $this->managedStockItem();
        $assets = $stockItem->mediaAssets()->get()->keyBy('id');

        if ($assets->isEmpty()) {
            throw ValidationException::withMessages([
                'stockImages' => __('skus.image_file_required'),
            ]);
        }

        $orderedIds = collect($this->imageAssetOrder)
            ->filter(fn ($id): bool => is_numeric($id) && $assets->has((int) $id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $missingIds = $assets->keys()
            ->map(fn ($id): int => (int) $id)
            ->diff($orderedIds)
            ->values();

        $finalOrder = $orderedIds->merge($missingIds)->values();
        $primaryId = $finalOrder->first();

        DB::transaction(function () use ($assets, $finalOrder, $primaryId): void {
            foreach ($finalOrder as $position => $assetId) {
                $asset = $assets->get($assetId);

                if (! $asset) {
                    continue;
                }

                $asset->forceFill([
                    'sort_order' => $position + 1,
                    'is_primary' => $asset->id === $primaryId,
                ])->save();
            }
        });

        $stockItem = $stockItem->refresh();
        $this->syncImageAssetState($stockItem);
        $this->dispatch('stock-images-synced', images: $this->stockImageCards($stockItem));

        if ($flash) {
            $this->flashStatus(__('skus.image_order_updated'));
        }
    }

    private function syncImageAssetState(StockItem $stockItem): void
    {
        $assets = $stockItem->mediaAssets()->get();
        $this->imageAssetOrder = $assets->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return array<int, TemporaryUploadedFile>
     */
    private function orderedStockImages(): array
    {
        $images = array_values($this->stockImages);

        $ordered = [];

        foreach ($this->stockImageOrder as $index) {
            if (! is_numeric($index) || ! array_key_exists((int) $index, $images)) {
                continue;
            }

            $ordered[] = $images[(int) $index];
        }

        if ($ordered !== []) {
            return $ordered;
        }

        return $images;
    }

    private function stockImageFileName(StockItem $stockItem, int $sortOrder, TemporaryUploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';

        return $this->safeStockImageBaseName($stockItem).'-'.str_pad((string) $sortOrder, 2, '0', STR_PAD_LEFT).'.'.strtolower($extension);
    }

    private function safeStockImageBaseName(StockItem $stockItem): string
    {
        $base = Str::of($stockItem->code)
            ->upper()
            ->replaceMatches('/[^A-Z0-9_-]+/', '')
            ->trim('-_')
            ->toString();

        return $base !== '' ? $base : 'STOCK-'.$stockItem->id;
    }

    private function resetAliasForm(): void
    {
        $this->aliasBarcode = '';
        $this->aliasBarcodeType = 'other';
        $this->aliasLabel = '';
        $this->resetAliasEditForm();
    }

    private function resetAliasEditForm(): void
    {
        $this->editingAliasId = null;
        $this->aliasEdit = [
            'barcode' => '',
            'barcode_type' => 'other',
            'label' => '',
        ];
    }

    private function stockItemPrimaryBarcode(Sku $sku): ?string
    {
        $stockItem = $sku->stockItem;

        if (! $stockItem instanceof StockItem) {
            return null;
        }

        $alias = $stockItem->barcodeAliases
            ->where('is_active', true)
            ->sortByDesc('is_primary')
            ->first();

        return $alias instanceof BarcodeAlias ? $alias->barcode : null;
    }

    public function barcodeAliasTypeOptions(): array
    {
        return collect(BarcodeAlias::BARCODE_TYPES)
            ->mapWithKeys(fn (string $type): array => [$type => __('common.barcode_types.'.$type)])
            ->all();
    }

    public function mediaUrl(?MediaAsset $asset): ?string
    {
        if (! $asset) {
            return null;
        }

        return route('media.show', $asset);
    }

    public function stockImageCards(StockItem $stockItem): array
    {
        return $stockItem->mediaAssets()
            ->get()
            ->map(fn (MediaAsset $asset): array => [
                'id' => (int) $asset->id,
                'name' => $asset->file_name,
                'url' => $this->mediaUrl($asset),
                'width' => $asset->width,
                'height' => $asset->height,
            ])
            ->values()
            ->all();
    }

    public function canImportAmazonImage(Sku $sku): bool
    {
        return $sku->stock_item_id !== null
            && $sku->shop?->platform === 'amazon'
            && filled($sku->platform_product_id);
    }

    private function downloadAmazonImage(string $url): array
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            throw ValidationException::withMessages(['amazonImage' => __('skus.amazon_image_download_failed')]);
        }

        try {
            $response = Http::timeout(15)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw ValidationException::withMessages(['amazonImage' => __('skus.amazon_image_download_failed')]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages(['amazonImage' => __('skus.amazon_image_download_failed')]);
        }

        $contentLength = trim((string) $response->header('Content-Length'));

        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['amazonImage' => __('skus.amazon_image_too_large')]);
        }

        $bytes = $response->body();

        if (strlen($bytes) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['amazonImage' => __('skus.amazon_image_too_large')]);
        }

        $size = @getimagesizefromstring($bytes);

        if (! $size || ! isset($size['mime'])) {
            throw ValidationException::withMessages(['amazonImage' => __('skus.amazon_image_not_image')]);
        }

        $extension = match ($size['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if ($extension === null) {
            throw ValidationException::withMessages(['amazonImage' => __('skus.amazon_image_not_image')]);
        }

        return [
            'bytes' => $bytes,
            'extension' => $extension,
            'mime_type' => $size['mime'],
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
        ];
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableDecimal(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableId(string $value): ?int
    {
        return trim($value) === '' ? null : (int) $value;
    }

    private function flashStatus(string $message): void
    {
        session()->flash('status', $message);
    }

    private function flashError(string $message): void
    {
        session()->flash('error', $message);
    }

    private function draftValue(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private function logisticsDraftValue(string $field, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($field === 'weight_value') {
            return number_format((float) $value, 0, '.', '');
        }

        if (in_array($field, ['length_value', 'width_value', 'height_value'], true)) {
            return number_format((float) $value, 1, '.', '');
        }

        return $this->draftValue($value);
    }
}
