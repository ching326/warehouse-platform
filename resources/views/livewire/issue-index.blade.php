<div class="issue-index-page">
    <x-flash-toast />

    <x-page-panel-header
        :title="__('issues.page_title')"
        :subtitle="__('issues.page_subtitle')"
        :show-nav="false"
    />

    <section class="table-shell flux-panel">
        <div class="issue-filter-stack">
            <div class="issue-filter-row issue-filter-row-primary">
                @if ($showTenantFilter)
                    <flux:select wire:model.live="tenantId" :label="__('issues.field_tenant')">
                        <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:select wire:model.live="typeFilter" :label="__('issues.field_issue_type')">
                    <flux:select.option value="">{{ __('issues.all_types') }}</flux:select.option>
                    @foreach ($types as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="statusFilter" :label="__('issues.field_status')">
                    <flux:select.option value="">{{ __('issues.all_statuses') }}</flux:select.option>
                    @foreach ($statuses as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button href="{{ route('issues.create') }}" variant="primary" wire:navigate>
                    {{ __('issues.btn_create') }}
                </flux:button>
            </div>

            <div class="issue-filter-row issue-filter-row-search">
                <flux:input
                    class="issue-global-search"
                    wire:model.live.debounce.300ms="search"
                    :label="__('common.search')"
                    :placeholder="__('issues.search_placeholder')"
                />
            </div>
        </div>

        <div class="table-action-row" data-testid="issue-selection-actions">
            <div class="selection-count-slot" aria-live="polite">
                @if (count($selectedIds) > 0)
                    <flux:badge color="blue">{{ count($selectedIds) }}</flux:badge>
                @endif
            </div>
            <div class="selection-action-group">
                <flux:button type="button" size="sm" variant="primary" wire:click="closeSelected" :disabled="count($selectedIds) === 0">
                    {{ __('issues.btn_close_case') }}
                </flux:button>
            </div>
        </div>

        <flux:table :paginate="$cases" class="data-table">
            <flux:table.columns>
                <flux:table.column></flux:table.column>
                <flux:table.column>{{ __('skus.col_image') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_issue_no') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_type') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_related_order') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_lines') }}</flux:table.column>
                <flux:table.column>{{ __('issues.field_note') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_reported_at') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_updated') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($cases as $case)
                    <flux:table.row :key="$case->id">
                        <flux:table.cell class="so-select-cell">
                            <label class="so-checkbox-hitbox">
                                <input
                                    type="checkbox"
                                    wire:model.live="selectedIds"
                                    value="{{ $case->id }}"
                                    aria-label="{{ __('issues.col_select') }} {{ $case->issue_no }}"
                                >
                            </label>
                        </flux:table.cell>
                        <flux:table.cell>
                            @php($image = $case->mediaAssets->first())
                            @if ($image)
                                <img class="product-thumbnail" src="{{ $image->url() }}" alt="{{ $image->file_name }}">
                            @else
                                <span class="product-thumbnail product-thumbnail-placeholder" aria-hidden="true"></span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-record-ref-link
                                :href="route('issues.show', $case)"
                                :value="$case->issue_no"
                                :copy-label="__('common.copy')"
                                :copied-label="__('common.copied')"
                            />
                            <span class="subtle">{{ $case->tenant->code }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $case->typeLabel() }}</flux:table.cell>
                        <flux:table.cell>
                            <select
                                class="table-control"
                                aria-label="{{ __('issues.field_status') }} {{ $case->issue_no }}"
                                wire:change="updateStatus({{ $case->id }}, $event.target.value)"
                            >
                                @foreach ($statuses as $value => $label)
                                    <option value="{{ $value }}" @selected($case->status === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($case->salesOrder)
                                <strong>{{ $case->salesOrder->platform_order_id ?: '#'.$case->salesOrder->id }}</strong>
                                <span class="subtle">{{ __('issues.related_sales_order') }}</span>
                            @elseif ($case->outboundOrder)
                                <strong>{{ $case->outboundOrder->ref ?: '#'.$case->outboundOrder->id }}</strong>
                                <span class="subtle">{{ __('issues.related_outbound_order') }}</span>
                            @else
                                <span class="muted-dash">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @foreach ($case->lines->take(2) as $line)
                                <div class="so-item-line">
                                    <strong>{{ $line->sku?->sku ?? $line->stockItem?->code ?? '-' }}</strong>
                                    <span class="subtle">{{ number_format($line->qty) }} x {{ $line->stockItem?->name ?? $line->sku?->displayName() ?? '-' }}</span>
                                </div>
                            @endforeach
                            @if ($case->lines->count() > 2)
                                <span class="subtle">+{{ $case->lines->count() - 2 }} {{ __('issues.more_lines') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <textarea
                                class="table-control table-note-input"
                                rows="2"
                                aria-label="{{ __('issues.field_note') }} {{ $case->issue_no }}"
                                wire:change="updateNote({{ $case->id }}, $event.target.value)"
                            >{{ $case->note }}</textarea>
                        </flux:table.cell>
                        <flux:table.cell>{{ $case->reported_at?->format('Y-m-d H:i') ?? '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $case->updated_at->format('Y-m-d H:i') }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="10">
                            <div class="empty-state">{{ __('issues.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .issue-filter-stack {
            display: grid;
            gap: 12px;
            margin-bottom: 14px;
        }

        .issue-filter-row {
            display: grid;
            gap: 12px;
            align-items: end;
        }

        .issue-filter-row-primary {
            grid-template-columns: repeat(3, minmax(150px, 190px)) max-content;
        }

        .issue-filter-row-search {
            grid-template-columns: minmax(280px, 520px);
        }

        .issue-global-search {
            width: 100%;
        }

        @media (max-width: 900px) {
            .issue-filter-row-primary,
            .issue-filter-row-search {
                grid-template-columns: 1fr;
            }
        }
    </style>
</div>
