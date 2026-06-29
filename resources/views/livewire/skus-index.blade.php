<div class="skus-page">
    @php
        $managedStockItem = $managedStockItem ?? null;
        $managedAliasSku = $managedAliasSku ?? null;
    @endphp

    <div class="sku-page-actions">
        <div>
            <div class="page-title-row">
                <strong>{{ __('skus.page_title') }}</strong>
                <button
                    type="button"
                    class="view-settings-trigger"
                    wire:click="openViewSettings"
                    aria-label="{{ __('skus.view_settings') }}"
                    title="{{ __('skus.view_settings') }}"
                >
                    <flux:icon.eye class="view-settings-trigger-icon" />
                </button>
            </div>
            <span>{{ __('skus.page_subtitle') }}</span>
        </div>
    </div>

    <div class="sku-view-tabs-row">
        <div class="view-switcher" role="group" aria-label="{{ __('skus.view_switcher_label') }}">
            @foreach ($views as $viewKey => $viewLabel)
                <button
                    type="button"
                    @class(['view-switcher-btn', 'is-active' => $view === $viewKey])
                    aria-pressed="{{ $view === $viewKey ? 'true' : 'false' }}"
                    wire:click="switchView('{{ $viewKey }}')"
                >
                    {{ $viewLabel }}
                </button>
            @endforeach
        </div>
    </div>

    <section class="table-shell flux-panel">
        <x-flash-toast />

        <div class="sku-toolbar">
            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('skus.search_label')"
                :placeholder="__('skus.search_placeholder')"
            />

            @if ($showTenantFilter)
                <flux:select wire:model.live="tenantId" :label="__('common.tenant')">
                    <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="shopId" :label="__('common.shop')">
                <flux:select.option value="">{{ __('skus.all_shops') }}</flux:select.option>
                @foreach ($shops as $shop)
                    <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="status" :label="__('skus.filter_status')">
                @foreach ($statuses as $option)
                    <flux:select.option value="{{ $option }}">{{ $this->statusLabel($option) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="skuType" :label="__('skus.field_sku_type')">
                <flux:select.option value="">{{ __('skus.all_types') }}</flux:select.option>
                @foreach ($skuTypes as $option)
                    <flux:select.option value="{{ $option }}">{{ $this->skuTypeLabel($option) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="productType" :label="__('skus.field_product_type')">
                <flux:select.option value="">{{ __('skus.all_product_types') }}</flux:select.option>
                @foreach ($productTypes as $type)
                    <flux:select.option value="{{ $type->slug }}">{{ $type->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div
            x-data="{
                selected: $wire.entangle('selectedIds'),
                visible: $wire.entangle('visibleSkuIds'),
                selectedList() { return (this.selected || []).map(String); },
                visibleList() { return (this.visible || []).map(String); },
                has() { return this.selectedList().length > 0; },
                single() { return this.selectedList().length === 1; },
                isSelected(id) { return this.selectedList().includes(String(id)); },
                toggleRow(id) {
                    id = String(id);
                    const list = this.selectedList();
                    const i = list.indexOf(id);

                    if (i === -1) {
                        this.selected = list.concat([id]);
                        return;
                    }

                    list.splice(i, 1);
                    this.selected = list;
                },
                get allVisibleSelected() {
                    const v = this.visibleList();
                    const selected = this.selectedList();
                    return v.length > 0 && v.every((id) => selected.includes(id));
                },
                get someVisibleSelected() {
                    const v = this.visibleList();
                    const selected = this.selectedList();
                    const n = v.filter((id) => selected.includes(id)).length;
                    return n > 0 && n < v.length;
                },
                toggleAll() {
                    const v = this.visibleList();

                    if (this.allVisibleSelected) {
                        this.selected = this.selectedList().filter((id) => ! v.includes(id));
                        return;
                    }

                    this.selected = Array.from(new Set(this.selectedList().concat(v)));
                },
            }"
        >
        <div class="table-action-row sku-selection-action-row" data-testid="sku-selection-actions">
            <div class="selection-count-slot" aria-live="polite">
                <flux:badge color="blue" x-show="has()" x-cloak>
                    <span x-text="selectedList().length"></span>
                </flux:badge>
            </div>
            <div class="selection-action-group" data-testid="sku-bulk-actions">
                <flux:button type="button" size="sm" variant="outline" disabled x-show="! single()">
                    {{ __('skus.btn_edit') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="editSelectedSku" x-show="single()" x-cloak>
                    {{ __('skus.btn_edit') }}
                </flux:button>

                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('skus.action_deactivate') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="bulkDeactivate" wire:confirm="{{ __('skus.confirm_deactivate') }}" x-show="has()" x-cloak>
                    {{ __('skus.action_deactivate') }}
                </flux:button>

                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('skus.action_reactivate') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="bulkReactivate" x-show="has()" x-cloak>
                    {{ __('skus.action_reactivate') }}
                </flux:button>

                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('skus.action_delete_permanently') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="danger" wire:click="bulkDelete" wire:confirm="{{ __('skus.confirm_delete_permanently') }}" x-show="has()" x-cloak>
                    {{ __('skus.action_delete_permanently') }}
                </flux:button>
            </div>

            <div class="sales-order-page-actions inline-page-actions" data-testid="sku-page-actions">
                <a href="{{ route('skus.import') }}" class="inline-page-action-link" wire:navigate>
                    <flux:icon.arrow-up-tray />
                    {{ __('sku_import.btn_import') }}
                </a>
                <a href="{{ route('skus.create') }}" class="inline-page-action-link" wire:navigate>
                    <flux:icon.plus />
                    {{ __('skus.btn_create') }}
                </a>
            </div>
        </div>

        @if ($view === 'detailed')
            <flux:table class="sku-table sku-detailed-table">
                <flux:table.columns>
                    <flux:table.column>
                        <label class="so-checkbox-hitbox so-checkbox-hitbox-header" title="{{ __('skus.select_visible_skus') }}">
                            <input
                                type="checkbox"
                                x-bind:checked="allVisibleSelected"
                                x-bind:indeterminate.prop="someVisibleSelected"
                                x-on:change="toggleAll()"
                                aria-label="{{ __('skus.select_visible_skus') }}"
                            >
                        </label>
                    </flux:table.column>
                    <flux:table.column>{{ __('skus.col_image') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_sku') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_stock_item') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_shop') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_platform_ids') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_packaging') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($skus as $sku)
                        <flux:table.row :key="$sku->id">
                            <flux:table.cell class="so-select-cell">
                                <label class="so-checkbox-hitbox">
                                    <input
                                        type="checkbox"
                                        x-bind:checked="isSelected({{ $sku->id }})"
                                        x-on:change="toggleRow({{ $sku->id }})"
                                        aria-label="{{ __('skus.select_sku') }} {{ $sku->sku }}"
                                    >
                                </label>
                            </flux:table.cell>
                            <flux:table.cell>
                                @include('livewire.partials.stock-item-thumbnail', ['stockItem' => $sku->stockItem, 'interactive' => true])
                            </flux:table.cell>
                            <flux:table.cell class="sku-primary-cell">
                                <strong>{{ $sku->sku }}</strong>
                                <span>{{ $sku->name }}</span>
                                <small>{{ $this->skuTypeLabel($sku->sku_type) }}</small>
                                @if ($sku->status === 'inactive')
                                    <flux:badge color="zinc">{{ __('skus.status_inactive') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="sku-stock-cell">
                                @if ($sku->stockItem)
                                    <strong>{{ $this->stockItemDisplayCode($sku->stockItem) }}</strong>
                                    <span>{{ $sku->stockItem->name }}</span>
                                    <small>{{ $sku->stockItem->barcode ?? __('skus.no_barcode') }}</small>
                                @elseif ($sku->sku_type === 'virtual_bundle')
                                    <strong>{{ __('skus.virtual_bundle') }}</strong>
                                    <span title="{{ $this->bundleComposition($sku, 999) }}">{{ $this->bundleComposition($sku) }}</span>
                                @else
                                    <flux:badge color="amber">{{ __('skus.missing_stock_item') }}</flux:badge>
                                    <span>{{ __('skus.missing_stock_item_hint') }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="sku-muted-cell">
                                @if ($sku->shop)
                                    <strong>{{ $sku->shop->code }}</strong>
                                    <span>{{ $sku->shop->name }}</span>
                                @else
                                    <span class="muted-dash">{{ __('skus.no_shop') }}</span>
                                @endif
                                <small>{{ $sku->tenant->code }} / {{ $sku->tenant->name }}</small>
                            </flux:table.cell>
                            <flux:table.cell class="sku-platform-cell">
                                <span>{{ $sku->platform_sku ?: '-' }}</span>
                                <small>{{ $sku->platform_product_id ?: '-' }} / {{ $sku->platform_variant_id ?: '-' }}</small>
                                <small>{{ $sku->platform_label_code ?: '-' }}</small>
                            </flux:table.cell>
                            <flux:table.cell class="sku-muted-cell">
                                @if ($sku->defaultPackagingMaterial)
                                    <strong>{{ $sku->defaultPackagingMaterial->code }}</strong>
                                @else
                                    <span class="muted-dash">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="sku-row-actions">
                                    @if ($this->canImportAmazonImage($sku))
                                        <flux:button type="button" size="xs" variant="subtle" wire:click="importAmazonImage({{ $sku->id }})">
                                            {{ __('skus.fetch_amazon_image') }}
                                        </flux:button>
                                    @endif
                                    <flux:button type="button" size="sm" variant="primary" wire:click="openAliasPanel({{ $sku->id }})">
                                        {{ __('skus.manage_aliases') }}
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="{{ $currentColumnCount }}">
                                <div class="empty-state">{{ __('skus.empty_state') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        @elseif ($view === 'logistics')
            <flux:table class="sku-table sku-logistics-table">
                <flux:table.columns>
                    <flux:table.column>
                        <label class="so-checkbox-hitbox so-checkbox-hitbox-header" title="{{ __('skus.select_visible_skus') }}">
                            <input
                                type="checkbox"
                                x-bind:checked="allVisibleSelected"
                                x-bind:indeterminate.prop="someVisibleSelected"
                                x-on:change="toggleAll()"
                                aria-label="{{ __('skus.select_visible_skus') }}"
                            >
                        </label>
                    </flux:table.column>
                    <flux:table.column>{{ __('skus.col_image') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_stock_item') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_sku') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_name') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_short_name') }}</flux:table.column>
                    <flux:table.column class="sku-number-column">{{ __('skus.col_weight_g') }}</flux:table.column>
                    <flux:table.column class="sku-number-column">{{ __('skus.col_length_cm') }}</flux:table.column>
                    <flux:table.column class="sku-number-column">{{ __('skus.col_width_cm') }}</flux:table.column>
                    <flux:table.column class="sku-number-column">{{ __('skus.col_height_cm') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_packaging') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_shipping_method') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($skus as $sku)
                        <flux:table.row :key="$sku->id">
                            <flux:table.cell class="so-select-cell">
                                <label class="so-checkbox-hitbox">
                                    <input
                                        type="checkbox"
                                        x-bind:checked="isSelected({{ $sku->id }})"
                                        x-on:change="toggleRow({{ $sku->id }})"
                                        aria-label="{{ __('skus.select_sku') }} {{ $sku->sku }}"
                                    >
                                </label>
                            </flux:table.cell>
                            <flux:table.cell>
                                @include('livewire.partials.stock-item-thumbnail', ['stockItem' => $sku->stockItem, 'interactive' => true])
                            </flux:table.cell>
                            <flux:table.cell class="sku-stock-cell">
                                @if ($sku->stockItem)
                                    <strong>{{ $this->stockItemDisplayCode($sku->stockItem) }}</strong>
                                @elseif ($sku->sku_type === 'virtual_bundle')
                                    <strong>{{ __('skus.virtual_bundle') }}</strong>
                                    <span title="{{ $this->bundleComposition($sku, 999) }}">{{ $this->bundleComposition($sku) }}</span>
                                @else
                                    <flux:badge color="amber">{{ __('skus.missing_stock_item') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="sku-primary-cell">
                                <strong>{{ $sku->sku }}</strong>
                                @if ($sku->status === 'inactive')
                                    <flux:badge color="zinc">{{ __('skus.status_inactive') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="sku-primary-cell">
                                @if ($sku->stockItem)
                                    @php
                                        $localizedStockItemName = $this->logisticsStockItemName($sku);
                                    @endphp
                                    @if ($localizedStockItemName !== '')
                                        <span title="{{ $localizedStockItemName }}">{{ $localizedStockItemName }}</span>
                                    @else
                                        <input
                                            type="text"
                                            wire:model="logisticsDrafts.{{ $sku->id }}.localized_name"
                                            wire:blur="saveLogisticsField({{ $sku->id }}, 'localized_name')"
                                        >
                                    @endif
                                @else
                                    <span class="muted-dash">-</span>
                                @endif
                            </flux:table.cell>
                            @foreach (['short_name', 'weight_value', 'length_value', 'width_value', 'height_value'] as $field)
                                <flux:table.cell class="{{ $field === 'short_name' ? 'sku-short-name-cell' : 'sku-number-cell' }}">
                                    @if ($sku->stockItem)
                                        <input
                                            type="{{ $field === 'short_name' ? 'text' : 'number' }}"
                                            step="{{ $field === 'weight_value' ? '1' : '0.1' }}"
                                            min="0"
                                            wire:model="logisticsDrafts.{{ $sku->id }}.{{ $field }}"
                                            wire:blur="saveLogisticsField({{ $sku->id }}, '{{ $field }}')"
                                        >
                                    @else
                                        <span class="muted-dash">-</span>
                                    @endif
                                </flux:table.cell>
                            @endforeach
                            <flux:table.cell class="sku-select-cell">
                                <select wire:model="logisticsDrafts.{{ $sku->id }}.default_packaging_material_id" wire:change="saveLogisticsField({{ $sku->id }}, 'default_packaging_material_id')">
                                    <option value=""></option>
                                    @foreach ($packagingMaterials as $material)
                                        <option value="{{ $material->id }}">{{ $material->code }}</option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                            <flux:table.cell class="sku-select-cell">
                                <select wire:model="logisticsDrafts.{{ $sku->id }}.default_shipping_method_id" wire:change="saveLogisticsField({{ $sku->id }}, 'default_shipping_method_id')">
                                    <option value=""></option>
                                    @php
                                        $currentShippingMethodId = (string) ($sku->default_shipping_method_id ?? '');
                                    @endphp
                                    @foreach ($shippingMethods as $method)
                                        @php
                                            $isInactiveShippingMethod = $method->status !== 'active';
                                            $isCurrentShippingMethod = (string) $method->id === $currentShippingMethodId;
                                        @endphp
                                        @continue($isInactiveShippingMethod && ! $isCurrentShippingMethod)

                                        <option value="{{ $method->id }}" @disabled($isInactiveShippingMethod && ! $isCurrentShippingMethod)>
                                            {{ $method->name }}
                                            @if ($method->status !== 'active')
                                                ({{ __('skus.inactive_shipping_method') }})
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="{{ $currentColumnCount }}">
                                <div class="empty-state">{{ __('skus.empty_state') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        @else
            <flux:table class="sku-table sku-flat-table sku-{{ $view }}-table">
                <flux:table.columns>
                    <flux:table.column>
                        <label class="so-checkbox-hitbox so-checkbox-hitbox-header" title="{{ __('skus.select_visible_skus') }}">
                            <input
                                type="checkbox"
                                x-bind:checked="allVisibleSelected"
                                x-bind:indeterminate.prop="someVisibleSelected"
                                x-on:change="toggleAll()"
                                aria-label="{{ __('skus.select_visible_skus') }}"
                            >
                        </label>
                    </flux:table.column>
                    @foreach ($flatColumns as $label)
                        <flux:table.column>{{ $label }}</flux:table.column>
                    @endforeach
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($skus as $sku)
                        <flux:table.row :key="$sku->id">
                            <flux:table.cell class="so-select-cell">
                                <label class="so-checkbox-hitbox">
                                    <input
                                        type="checkbox"
                                        x-bind:checked="isSelected({{ $sku->id }})"
                                        x-on:change="toggleRow({{ $sku->id }})"
                                        aria-label="{{ __('skus.select_sku') }} {{ $sku->sku }}"
                                    >
                                </label>
                            </flux:table.cell>
                            @foreach ($flatColumns as $key => $label)
                                <flux:table.cell @class([
                                    'sku-flat-cell',
                                    'sku-name-cell' => $key === 'name',
                                    'sku-image-cell' => $key === 'image',
                                    'sku-narrow-cell' => in_array($key, ['variation_code', 'type'], true),
                                    'sku-product-type-cell' => $key === 'product_type',
                                ])>
                                    @if ($view === 'catalog' && $key === 'image')
                                        @include('livewire.partials.stock-item-thumbnail', ['stockItem' => $sku->stockItem, 'interactive' => true])
                                    @elseif ($view === 'catalog' && $key === 'product_type')
                                        @if ($sku->stockItem)
                                            <select
                                                class="table-control compact-select-control"
                                                wire:model="catalogDrafts.{{ $sku->id }}.product_type"
                                                wire:change="saveCatalogField({{ $sku->id }}, 'product_type')"
                                                aria-label="{{ __('skus.col_product_type') }} {{ $sku->sku }}"
                                            >
                                                @foreach ($productTypes as $type)
                                                    <option value="{{ $type->slug }}">{{ $type->name }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="muted-dash">-</span>
                                        @endif
                                    @else
                                        @php
                                            $cellValue = $this->flatCellValue($sku, $key);
                                        @endphp
                                        <span title="{{ $cellValue }}">{{ $cellValue }}</span>
                                        @if ($key === 'sku' && $sku->status === 'inactive')
                                            <flux:badge color="zinc">{{ __('skus.status_inactive') }}</flux:badge>
                                        @endif
                                    @endif
                                </flux:table.cell>
                            @endforeach
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="{{ $currentColumnCount }}">
                                <div class="empty-state">{{ __('skus.empty_state') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        @endif

        <div class="sku-pagination-row">
            @if ($skus->total() > 0)
                <div class="sku-pagination-summary">
                    <x-rows-per-page-select :options="$perPageOptions" />
                    <span>
                        {!! __('Showing') !!} {{ $skus->firstItem() }} {!! __('to') !!} {{ $skus->lastItem() }} {!! __('of') !!} {{ $skus->total() }} {!! __('results') !!}
                    </span>
                </div>
            @else
                <div></div>
            @endif

            @if ($skus->hasPages())
                <div class="sku-pagination-controls">
                    @if ($skus->onFirstPage())
                        <span class="sku-pagination-button is-disabled" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">&lsaquo;</span>
                    @else
                        <button type="button" class="sku-pagination-button" wire:click="previousPage('{{ $skus->getPageName() }}')" aria-label="{{ __('pagination.previous') }}">&lsaquo;</button>
                    @endif

                    @php
                        $previousPaginationPage = null;
                    @endphp
                    @foreach ($paginationPages as $page)
                        @if ($previousPaginationPage && $page > $previousPaginationPage + 1)
                            <span class="sku-pagination-ellipsis" aria-hidden="true">...</span>
                        @endif

                        @if ($page === $skus->currentPage())
                            <span class="sku-pagination-button is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <button type="button" class="sku-pagination-button" wire:click="gotoPage({{ $page }}, '{{ $skus->getPageName() }}')">{{ $page }}</button>
                        @endif

                        @php
                            $previousPaginationPage = $page;
                        @endphp
                    @endforeach

                    @if ($skus->hasMorePages())
                        <button type="button" class="sku-pagination-button" wire:click="nextPage('{{ $skus->getPageName() }}')" aria-label="{{ __('pagination.next') }}">&rsaquo;</button>
                    @else
                        <span class="sku-pagination-button is-disabled" aria-disabled="true" aria-label="{{ __('pagination.next') }}">&rsaquo;</span>
                    @endif
                </div>
            @endif
        </div>
        </div>

        @if ($managedStockItem)
            <div class="image-panel-backdrop app-modal-backdrop">
                <section class="image-panel app-modal-panel tracking-import-modal flux-panel" style="--app-modal-width: 920px;" aria-label="{{ __('skus.manage_images') }}">
                    @php
                        $imageCards = $this->stockImageCards($managedStockItem);
                    @endphp
                    <header class="image-panel-header tracking-import-header">
                        <div>
                            <h2>{{ __('skus.manage_images') }}</h2>
                            <span>{{ $managedStockItem->code }} - {{ $managedStockItem->name }}</span>
                        </div>
                        <button type="button" class="modal-icon-close" wire:click="closeImagePanel" aria-label="{{ __('skus.btn_cancel') }}">&times;</button>
                    </header>

                    <form
                        class="image-upload-form"
                        wire:submit="saveStockImages"
                        x-on:submit="if (uploading) { $event.preventDefault(); }"
                        x-data="{
                            uploaded: @js($imageCards),
                            previews: [],
                            uploading: false,
                            setFiles(event) {
                                this.revokePreviews();
                                this.previews = Array.from(event.target.files || [])
                                    .filter((file) => file.type.startsWith('image/'))
                                    .map((file, originalIndex) => ({
                                        name: file.name,
                                        key: originalIndex,
                                        url: URL.createObjectURL(file),
                                    }));
                                this.syncUploadOrder();
                            },
                            moveUploaded(index, direction) {
                                const target = index + direction;

                                if (target < 0 || target >= this.uploaded.length) {
                                    return;
                                }

                                const items = [...this.uploaded];
                                const moved = items.splice(index, 1)[0];
                                items.splice(target, 0, moved);
                                this.uploaded = items;
                                this.syncAssetOrder();
                            },
                            movePreview(index, direction) {
                                const target = index + direction;

                                if (target < 0 || target >= this.previews.length) {
                                    return;
                                }

                                const items = [...this.previews];
                                const moved = items.splice(index, 1)[0];
                                items.splice(target, 0, moved);
                                this.previews = items;
                                this.syncUploadOrder();
                            },
                            removePreview(index) {
                                const removed = this.previews[index];

                                if (removed) {
                                    URL.revokeObjectURL(removed.url);
                                }

                                this.previews = this.previews.filter((_, itemIndex) => itemIndex !== index);
                                this.syncUploadOrder();
                            },
                            deleteUploaded(index) {
                                const image = this.uploaded[index];

                                if (! image) {
                                    return;
                                }

                                this.uploaded = this.uploaded.filter((_, itemIndex) => itemIndex !== index);
                                this.syncAssetOrder();
                                this.$wire.deleteImage(image.id);
                            },
                            syncAssetOrder() {
                                this.$wire.set('imageAssetOrder', this.uploaded.map((image) => image.id), false);
                            },
                            syncUploadOrder() {
                                this.$wire.set('stockImageOrder', this.previews.map((preview) => preview.key), false);
                            },
                            resetPreviews() {
                                this.revokePreviews();
                                this.previews = [];
                                this.uploading = false;
                                this.syncUploadOrder();
                                if (this.$refs.stockImageInput) {
                                    this.$refs.stockImageInput.value = '';
                                }
                            },
                            revokePreviews() {
                                this.previews.forEach((preview) => URL.revokeObjectURL(preview.url));
                            },
                            init() {
                                this.syncAssetOrder();
                            },
                        }"
                        x-on:stock-images-reset.window="resetPreviews()"
                        x-on:stock-images-synced.window="uploaded = $event.detail.images || []; syncAssetOrder()"
                        x-on:livewire:navigating.window="revokePreviews()"
                    >
                        <label class="image-drop-zone tracking-import-dropzone">
                            <strong>{{ __('skus.image_drop_title') }}</strong>
                            <small>{{ __('skus.image_drop_hint') }}</small>
                            <input
                                x-ref="stockImageInput"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                capture="environment"
                                multiple
                                wire:model="stockImages"
                                x-on:change="setFiles($event)"
                                x-on:livewire-upload-start="uploading = true"
                                x-on:livewire-upload-finish="uploading = false"
                                x-on:livewire-upload-error="uploading = false"
                                x-on:livewire-upload-cancel="uploading = false"
                            >
                        </label>
                        @error('stockImages')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                        @error('stockImages.*')
                            <span class="field-error">{{ $message }}</span>
                        @enderror

                        <div class="image-selected-preview" x-show="uploaded.length > 0 || previews.length > 0">
                            <template x-for="(image, index) in uploaded" :key="'saved-' + image.id">
                                <article class="image-preview-item">
                                    <div class="image-preview-frame">
                                        <img :src="image.url" :alt="image.name">
                                        <button type="button" class="image-preview-remove-button" x-on:click="deleteUploaded(index)" aria-label="{{ __('skus.delete_image') }}">
                                            &times;
                                        </button>
                                    </div>
                                    <div>
                                        <strong x-text="image.name"></strong>
                                        <span x-show="index === 0">{{ __('skus.primary_image') }}</span>
                                        <small x-show="image.width && image.height" x-text="image.width + ' x ' + image.height"></small>
                                    </div>
                                    <div class="image-preview-actions">
                                        <button type="button" class="image-preview-order-button" x-on:click="moveUploaded(index, -1)" x-bind:disabled="index === 0" title="{{ __('skus.move_image_up') }}">
                                            &larr;
                                        </button>
                                        <button type="button" class="image-preview-order-button" x-on:click="moveUploaded(index, 1)" x-bind:disabled="index === uploaded.length - 1" title="{{ __('skus.move_image_down') }}">
                                            &rarr;
                                        </button>
                                    </div>
                                </article>
                            </template>

                            <template x-for="(preview, index) in previews" :key="'new-' + preview.name + '-' + index">
                                <article class="image-preview-item">
                                    <div class="image-preview-frame">
                                        <img :src="preview.url" :alt="preview.name">
                                        <button type="button" class="image-preview-remove-button" x-on:click="removePreview(index)" aria-label="{{ __('common.delete') }}">
                                            &times;
                                        </button>
                                    </div>
                                    <div>
                                        <strong x-text="preview.name"></strong>
                                        <span x-text="uploaded.length === 0 && index === 0 ? @js(__('skus.primary_image')) : @js(__('skus.image_preview_ready'))"></span>
                                    </div>
                                    <div class="image-preview-actions">
                                        <button type="button" class="image-preview-order-button" x-on:click="movePreview(index, -1)" x-bind:disabled="index === 0" title="{{ __('skus.move_image_up') }}">
                                            &larr;
                                        </button>
                                        <button type="button" class="image-preview-order-button" x-on:click="movePreview(index, 1)" x-bind:disabled="index === previews.length - 1" title="{{ __('skus.move_image_down') }}">
                                            &rarr;
                                        </button>
                                    </div>
                                </article>
                            </template>
                        </div>

                        <div class="empty-state" x-show="uploaded.length === 0 && previews.length === 0">{{ __('skus.no_images') }}</div>

                        <footer class="tracking-import-footer image-upload-footer">
                            <flux:button
                                type="submit"
                                variant="primary"
                                x-bind:disabled="uploading"
                                wire:loading.attr="disabled"
                                wire:target="stockImages,saveStockImages"
                            >
                                {{ __('skus.upload_image') }}
                            </flux:button>
                        </footer>
                    </form>
                </section>
            </div>
        @endif

        @if ($managedAliasSku)
            <div class="image-panel-backdrop app-modal-backdrop">
                <section class="image-panel app-modal-panel flux-panel" style="--app-modal-width: 760px;" aria-label="{{ __('skus.manage_aliases') }}">
                    <div class="image-panel-header">
                        <div>
                            <strong>{{ __('skus.manage_aliases') }}</strong>
                            <span>{{ $managedAliasSku->sku }} - {{ $managedAliasSku->name }}</span>
                        </div>
                        <button type="button" class="modal-icon-close" wire:click="closeAliasPanel" aria-label="{{ __('skus.btn_cancel') }}">&times;</button>
                    </div>

                    <form class="alias-form" wire:submit="createBarcodeAlias">
                        <flux:input wire:model="aliasBarcode" required :label="__('skus.alias_barcode')" />
                        <flux:select wire:model="aliasBarcodeType" :label="__('skus.alias_barcode_type')">
                            @foreach ($this->barcodeAliasTypeOptions() as $type => $label)
                                <flux:select.option value="{{ $type }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="aliasLabel" :label="__('skus.alias_label')" />
                        <flux:button class="alias-add-button" type="submit" variant="primary">{{ __('skus.alias_add') }}</flux:button>
                    </form>

                    @foreach (['aliasBarcode', 'aliasBarcodeType', 'aliasLabel', 'normalized_barcode'] as $field)
                        @error($field)
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    @endforeach

                    <div class="alias-list">
                        <div class="alias-list-group">
                            <strong>{{ __('skus.alias_target_sku') }}</strong>
                            <span class="alias-group-description">{{ __('skus.alias_target_sku_hint') }}</span>
                            @forelse ($managedAliasSku->barcodeAliases as $alias)
                                @include('livewire.partials.barcode-alias-row', ['alias' => $alias, 'keyPrefix' => 'sku'])
                            @empty
                                <div class="empty-state">{{ __('skus.no_aliases') }}</div>
                            @endforelse
                        </div>

                        @if ($managedAliasSku->stockItem)
                            <div class="alias-list-group">
                                <strong>{{ __('skus.alias_target_stock_item') }} / {{ $managedAliasSku->stockItem->code }}</strong>
                                <span class="alias-group-description">{{ __('skus.alias_target_stock_item_hint') }}</span>
                                @forelse ($managedAliasSku->stockItem->barcodeAliases as $alias)
                                    @include('livewire.partials.barcode-alias-row', ['alias' => $alias, 'keyPrefix' => 'stock'])
                                @empty
                                    <div class="empty-state">{{ __('skus.no_aliases') }}</div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                </section>
            </div>
        @endif

        @if ($viewSettingsOpen)
            <div class="image-panel-backdrop app-modal-backdrop">
                <section class="image-panel app-modal-panel flux-panel" style="--app-modal-width: 460px;" aria-label="{{ __('skus.view_settings_title') }}">
                    <div class="image-panel-header">
                        <div>
                            <strong>{{ __('skus.view_settings_title') }}</strong>
                        </div>
                        <button type="button" class="modal-icon-close" wire:click="closeViewSettings" aria-label="{{ __('skus.btn_cancel') }}">&times;</button>
                    </div>

                    <form class="view-settings-form" wire:submit="saveViewSettings">
                        <label class="view-settings-field">
                            <span>{{ __('skus.stock_item_code_display') }}</span>
                            <select wire:model="stockItemCodeDisplay">
                                @foreach ($this->stockItemCodeDisplayOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        @if ($canSaveDefaultView)
                            <label class="view-settings-field">
                                <span>{{ __('skus.default_view') }}</span>
                                <select wire:model="defaultView">
                                    <option value="">{{ __('skus.default_view_none') }}</option>
                                    @foreach ($views as $viewKey => $viewLabel)
                                        <option value="{{ $viewKey }}">{{ $viewLabel }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif

                        <footer class="tracking-import-footer">
                            <flux:button type="submit" variant="primary">{{ __('skus.view_settings_save') }}</flux:button>
                        </footer>
                    </form>
                </section>
            </div>
        @endif
    </section>

    <style>
        .alias-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            align-items: end;
        }

        .alias-list {
            display: grid;
            gap: 16px;
            margin-top: 18px;
        }

        .alias-add-button {
            min-height: 42px;
        }

        .alias-add-button,
        .alias-add-button * {
            font-size: 14px;
        }

        .alias-list-group {
            display: grid;
            gap: 8px;
        }

        .alias-group-description {
            color: #64748b;
            font-size: 13px;
        }

        .alias-list-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            background: #fff;
        }

        .alias-list-item > div {
            display: grid;
            gap: 6px;
            min-width: 0;
        }

        .alias-heading {
            display: grid;
            grid-template-columns: minmax(0, 160px) 58px 220px minmax(0, 1fr);
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .alias-heading strong {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .alias-type-select {
            width: 220px;
            min-height: 30px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: var(--ink);
            font-size: 13px;
            padding: 4px 8px;
        }

        .alias-type-display {
            color: #64748b;
            font-size: 13px;
        }

        .alias-row-actions {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            flex-wrap: wrap;
        }

        .alias-edit-form {
            display: grid;
            grid-template-columns: minmax(160px, 1fr) 220px minmax(160px, 1fr);
            gap: 8px;
            align-items: start;
            min-width: 0;
        }

        .alias-edit-input {
            width: 100%;
            min-height: 30px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: var(--ink);
            font-size: 13px;
            padding: 4px 8px;
        }

        .alias-deactivate-button,
        .alias-deactivate-button * {
            font-size: 13px;
        }

        .alias-list-item span,
        .alias-list-item small {
            color: #64748b;
            overflow-wrap: anywhere;
        }

        .alias-note {
            min-width: 0;
        }

        @media (max-width: 760px) {
            .alias-form,
            .alias-list-item {
                grid-template-columns: 1fr;
            }

            .alias-heading {
                grid-template-columns: 1fr;
            }

            .alias-type-select {
                width: 100%;
            }

            .alias-edit-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</div>
