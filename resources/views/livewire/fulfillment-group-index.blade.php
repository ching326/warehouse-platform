<div class="fulfillment-group-index-page">
    <x-flash-toast />

    @if ($pendingExportWarning)
        <div class="active-filter-row">
            <div class="export-warning-message">{{ $pendingExportWarning }}</div>
            @if ($pendingCourierExportCarrier)
                <flux:button type="button" size="sm" variant="primary" wire:click="confirmCourierExport">
                    {{ __('fulfillment_groups.courier_export_confirm_btn') }}
                </flux:button>
            @endif
        </div>
    @endif

    <section class="table-shell flux-panel">
        <div class="movement-toolbar">
            @if ($showTenantFilter)
                <flux:select wire:model.live="tenantId" :label="__('fulfillment_groups.field_tenant')">
                    <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="warehouseId" :label="__('fulfillment_groups.field_warehouse')">
                <flux:select.option value="">{{ __('fulfillment_groups.all_warehouses') }}</flux:select.option>
                @foreach ($warehouses as $warehouse)
                    <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" :label="__('fulfillment_groups.col_status')">
                <flux:select.option value="">{{ __('fulfillment_groups.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $status => $label)
                    <flux:select.option value="{{ $status }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <label class="fg-toggle">
                <input type="checkbox" wire:model.live="printWaiting" />
                {{ __('fulfillment_groups.filter_print_waiting') }}
            </label>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('common.search')"
                :placeholder="__('fulfillment_groups.search_placeholder')"
            />

            <flux:button href="{{ route('fulfillment-groups.create') }}" variant="primary" wire:navigate>
                {{ __('fulfillment_groups.btn_create') }}
            </flux:button>
        </div>

        @if (count($selectedIds) > 0)
            <div class="fg-batchbar">
                <span class="fg-batchbar-count">
                    {{ __('fulfillment_groups.selected_count', ['count' => count($selectedIds)]) }}
                </span>
                <span class="fg-batchbar-spacer"></span>
                <flux:button size="sm" variant="outline" wire:click="exportYamato">
                    {{ __('fulfillment_groups.batch_export_yamato') }}
                </flux:button>
                <flux:button size="sm" variant="outline" wire:click="exportSagawa">
                    {{ __('fulfillment_groups.batch_export_sagawa') }}
                </flux:button>
                <flux:button size="sm" variant="outline" wire:click="openTrackingImportModal">
                    {{ __('fulfillment_groups.batch_import_tracking') }}
                </flux:button>
                <flux:button size="sm" variant="primary" wire:click="markShipped">
                    {{ __('fulfillment_groups.btn_mark_shipped') }}
                </flux:button>
            </div>
        @endif

        <flux:table :paginate="$groups" class="data-table">
            <flux:table.columns>
                <flux:table.column class="fg-col-select"></flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_reference_no') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_shop') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_recipient') }}</flux:table.column>
                <flux:table.column align="end">{{ __('fulfillment_groups.col_orders_items') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_shipping') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_tracking') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_note') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_added') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_status') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($groups as $group)
                    @php
                        $members = $group->groupOrders;
                        $orderIds = $members
                            ->map(fn ($go) => $go->salesOrder?->platform_order_id)
                            ->filter()
                            ->values();
                        $shops = $members
                            ->map(fn ($go) => $go->salesOrder?->shop?->name)
                            ->filter()
                            ->unique()
                            ->values();
                        $itemQty = $members->sum(fn ($go) => $go->salesOrder
                            ? (int) $go->salesOrder->lines->sum('quantity')
                            : 0);
                        $arranged = $members->pluck('arranged_at')->filter()->min();
                        $printed = $members
                            ->map(fn ($go) => $go->salesOrder?->courier_csv_exported_at)
                            ->filter()
                            ->min();
                    @endphp
                    <flux:table.row :key="$group->id">
                        <flux:table.cell class="fg-col-select">
                            <input type="checkbox" wire:model.live="selectedIds" value="{{ $group->id }}" />
                        </flux:table.cell>

                        <flux:table.cell>
                            <a class="fg-ref-link" href="{{ route('fulfillment-groups.show', $group) }}" wire:navigate>
                                {{ $group->reference_no }}
                            </a>
                            <div class="fg-subtle">
                                @if ($orderIds->isEmpty())
                                    -
                                @elseif ($orderIds->count() === 1)
                                    {{ $orderIds->first() }}
                                @else
                                    {{ $orderIds->first() }} +{{ $orderIds->count() - 1 }}
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <strong>{{ $group->tenant->code }}</strong>
                            <div class="fg-subtle">
                                @if ($shops->count() === 1)
                                    {{ $shops->first() }}
                                @elseif ($shops->count() > 1)
                                    {{ __('fulfillment_groups.shops_count', ['count' => $shops->count()]) }}
                                @else
                                    -
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <strong>{{ $group->recipient_name ?: '-' }}</strong>
                            <div class="fg-subtle">{{ $group->recipient_city ?: $group->recipient_postal_code ?: '-' }}</div>
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <strong>{{ number_format($group->orders_count) }}</strong> / {{ number_format($itemQty) }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <select
                                class="fg-inline-input"
                                wire:change="updateShippingMethod({{ $group->id }}, $event.target.value)"
                            >
                                <option value="">-</option>
                                @foreach ($shippingMethods as $methodId => $methodName)
                                    <option value="{{ $methodId }}" @selected((string) $group->shipping_method_id === (string) $methodId)>{{ $methodName }}</option>
                                @endforeach
                            </select>
                        </flux:table.cell>

                        <flux:table.cell>
                            <input
                                type="text"
                                class="fg-inline-input"
                                value="{{ $trackingDrafts[$group->id] ?? '' }}"
                                placeholder="{{ __('fulfillment_groups.tracking_placeholder') }}"
                                wire:change="updateTracking({{ $group->id }}, $event.target.value)"
                            />
                        </flux:table.cell>

                        <flux:table.cell>
                            <input
                                type="text"
                                class="fg-inline-input"
                                value="{{ $noteDrafts[$group->id] ?? '' }}"
                                placeholder="{{ __('fulfillment_groups.note_placeholder') }}"
                                wire:change="updateNote({{ $group->id }}, $event.target.value)"
                            />
                        </flux:table.cell>

                        <flux:table.cell>
                            <div>{{ $arranged ? $arranged->format('Y-m-d H:i') : '-' }}</div>
                            <div class="fg-subtle">
                                {{ $printed ? __('fulfillment_groups.printed_at', ['time' => $printed->format('m-d H:i')]) : __('fulfillment_groups.not_printed') }}
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($group->status) }}">
                                {{ $this->statusLabel($group->status) }}
                            </flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="10">
                            <div class="empty-state">{{ __('fulfillment_groups.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    @if ($showTrackingImportModal)
        <div class="modal-backdrop tracking-import-backdrop" wire:key="fulfillment-tracking-import-modal">
            <section class="tracking-import-modal flux-panel">
                <header class="tracking-import-header">
                    <div>
                        <h2>{{ __('fulfillment_groups.tracking_import_title') }}</h2>
                        <p>{{ __('fulfillment_groups.tracking_import_subtitle') }}</p>
                    </div>
                    <flux:button type="button" variant="ghost" size="sm" wire:click="closeTrackingImportModal">
                        {{ __('fulfillment_groups.tracking_import_close_btn') }}
                    </flux:button>
                </header>

                <form
                    method="POST"
                    action="{{ route('fulfillment.tracking-import') }}"
                    enctype="multipart/form-data"
                    x-data="{ dragging: false, fileName: '' }"
                >
                    @csrf

                    <label
                        class="tracking-import-dropzone"
                        x-bind:class="{ 'is-dragging': dragging }"
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="
                            dragging = false;
                            const input = $refs.trackingFile;
                            input.files = $event.dataTransfer.files;
                            fileName = input.files.length ? input.files[0].name : '';
                        "
                    >
                        <input
                            x-ref="trackingFile"
                            class="tracking-import-file-input"
                            type="file"
                            name="tracking_file"
                            accept=".csv,.txt,text/csv,text/plain"
                            x-on:change="fileName = $event.target.files.length ? $event.target.files[0].name : ''"
                        >
                        <strong>{{ __('fulfillment_groups.tracking_import_drop_title') }}</strong>
                        <span>{{ __('fulfillment_groups.tracking_import_drop_hint') }}</span>
                        <span class="tracking-import-file-name" x-show="fileName" x-text="fileName"></span>
                    </label>

                    <footer class="tracking-import-footer">
                        <flux:button type="button" variant="ghost" wire:click="closeTrackingImportModal">
                            {{ __('common.cancel') }}
                        </flux:button>
                        <flux:button type="submit" variant="primary">
                            {{ __('fulfillment_groups.tracking_import_confirm_btn') }}
                        </flux:button>
                    </footer>
                </form>
            </section>
        </div>
    @endif

    <style>
        .active-filter-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .export-warning-message {
            white-space: pre-line;
            color: var(--ink);
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 12px;
        }

        .fg-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--ink);
            padding: 9px 12px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .fg-toggle input {
            width: 15px;
            height: 15px;
            margin: 0;
            accent-color: var(--accent);
        }

        .fg-batchbar {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            margin-bottom: 10px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #f8fafc;
        }

        .fg-batchbar-count {
            font-size: 12px;
            font-weight: 700;
            color: var(--ink);
        }

        .fg-batchbar-spacer {
            flex: 1 1 auto;
        }

        .fg-ref-link {
            color: var(--accent);
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
        }

        .fg-ref-link:hover {
            text-decoration: underline;
        }

        .fg-subtle {
            color: var(--muted);
            font-size: 11px;
        }

        .fg-inline-input {
            width: 100%;
            min-width: 120px;
            height: 30px;
            padding: 4px 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            font-size: 12px;
            background: #fff;
            color: var(--ink);
        }

        .fg-inline-input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .fg-col-select {
            width: 34px;
        }

        .tracking-import-backdrop {
            align-items: flex-start;
            padding-top: 72px;
        }

        .tracking-import-modal {
            width: min(1040px, calc(100vw - 48px));
            padding: 20px;
        }

        .tracking-import-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--line);
        }

        .tracking-import-header h2 {
            margin: 0 0 6px;
            font-size: 20px;
        }

        .tracking-import-header p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .tracking-import-dropzone {
            display: grid;
            place-items: center;
            gap: 10px;
            min-height: 138px;
            margin: 16px 0;
            border: 1px dashed var(--line);
            border-radius: 8px;
            background: #f8fafc;
            cursor: pointer;
            color: var(--muted);
        }

        .tracking-import-dropzone strong {
            color: var(--ink);
            font-size: 16px;
        }

        .tracking-import-dropzone.is-dragging {
            border-color: var(--accent);
            background: #eefaf8;
        }

        .tracking-import-file-input {
            display: none;
        }

        .tracking-import-file-name {
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
        }

        .tracking-import-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
    </style>
</div>
