<div class="issue-show-page">
    <x-flash-toast />
<section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ $case->issue_no }}</strong>
                <span>{{ $case->tenant->code }} / {{ $case->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="active-filter-row">
                <flux:badge>{{ $case->typeLabel() }}</flux:badge>
                <flux:badge color="{{ $case->statusColor() }}">{{ $case->statusLabel() }}</flux:badge>
            </div>
        </div>

        <div class="form-grid three">
            <div>
                <span class="subtle">{{ __('issues.field_sales_order') }}</span>
                @if ($case->salesOrder)
                    <a href="{{ route('sales.orders.show', $case->salesOrder) }}" wire:navigate><strong>{{ $case->salesOrder->platform_order_id ?: '#'.$case->salesOrder->id }}</strong></a>
                @else
                    <strong>-</strong>
                @endif
            </div>
            <div><span class="subtle">{{ __('issues.field_outbound_order') }}</span><strong>{{ $case->outboundOrder?->ref ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('issues.field_reported_at') }}</span><strong>{{ $case->reported_at?->format('Y-m-d H:i') ?? '-' }}</strong></div>
            <div><span class="subtle">{{ __('issues.field_reported_by') }}</span><strong>{{ $case->reported_by ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('issues.field_created_by') }}</span><strong>{{ $case->createdBy?->name ?: '-' }}</strong></div>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('media.section_photos') }}</strong>
                <span>{{ __('media.photos_hint') }}</span>
            </div>
        </div>

        <form class="image-upload-form" wire:submit="uploadPhoto">
            <label>
                <span>{{ __('media.image_file') }}</span>
                <input type="file" accept="image/*" capture="environment" wire:model="photo">
            </label>
            @error('photo')
                <span class="field-error">{{ $message }}</span>
            @enderror

            <flux:select wire:model="photoType" :label="__('media.image_type')">
                @foreach ($photoTypes as $type => $label)
                    <flux:select.option value="{{ $type }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button type="submit" variant="primary">{{ __('media.upload_image') }}</flux:button>
        </form>

        <div class="image-list">
            @forelse ($case->mediaAssets as $asset)
                <article class="image-list-item" wire:key="issue-media-{{ $asset->id }}">
                    <a href="{{ $asset->url() }}" target="_blank">
                        <img src="{{ $asset->url() }}" alt="{{ $asset->file_name }}">
                    </a>
                    <div>
                        <strong>{{ $asset->file_name }}</strong>
                        <span>{{ $photoTypes[$asset->type] ?? $asset->type }}</span>
                        @if ($asset->width && $asset->height)
                            <small>{{ $asset->width }} x {{ $asset->height }}</small>
                        @endif
                    </div>
                    <flux:button type="button" size="xs" variant="danger" wire:click="deletePhoto({{ $asset->id }})">
                        {{ __('media.delete_image') }}
                    </flux:button>
                </article>
            @empty
                <div class="empty-state">{{ __('media.no_images') }}</div>
            @endforelse
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('issues.section_workflow') }}</strong>
                <span>{{ $case->isClosed() ? __('issues.read_only_hint') : __('issues.workflow_hint') }}</span>
            </div>
        </div>

        <div class="form-grid">
            <flux:select wire:model="status" :label="__('issues.field_status')" :disabled="$case->isClosed()">
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <label>
                <span>{{ __('issues.field_note') }}</span>
                <textarea wire:model="note" rows="3" @disabled($case->isClosed())></textarea>
            </label>
        </div>

        @if (! $case->isClosed())
            <div class="form-actions">
                <flux:button type="button" variant="primary" wire:click="saveIssue">{{ __('issues.btn_save_issue') }}</flux:button>
            </div>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('issues.section_lines') }}</strong>
                <span>{{ __('issues.no_inventory_hint') }}</span>
            </div>
        </div>

        <flux:table class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('issues.col_sku_stock') }}</flux:table.column>
                <flux:table.column align="end">{{ __('issues.field_qty') }}</flux:table.column>
                <flux:table.column>{{ __('issues.field_condition') }}</flux:table.column>
                <flux:table.column>{{ __('issues.field_action') }}</flux:table.column>
                <flux:table.column>{{ __('issues.field_line_note') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($case->lines as $line)
                    <flux:table.row :key="$line->id">
                        <flux:table.cell>
                            <strong>{{ $line->sku?->sku ?? '-' }}</strong>
                            <span class="subtle">{{ $line->stockItem?->code ?? __('common.sku_types.virtual_bundle') }} / {{ $line->stockItem?->name ?? $line->sku?->name ?? '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line->qty) }}</flux:table.cell>
                        <flux:table.cell>
                            <select wire:model="lineDrafts.{{ $line->id }}.condition" @disabled($case->isClosed())>
                                @foreach ($conditions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </flux:table.cell>
                        <flux:table.cell>
                            <select wire:model="lineDrafts.{{ $line->id }}.action" @disabled($case->isClosed())>
                                @foreach ($actions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </flux:table.cell>
                        <flux:table.cell><input type="text" wire:model="lineDrafts.{{ $line->id }}.note" @disabled($case->isClosed())></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        @if (! $case->isClosed())
            <div class="form-actions">
                <flux:button type="button" variant="primary" wire:click="saveLines">{{ __('issues.btn_save_lines') }}</flux:button>
            </div>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('return_orders.section_linked_return_orders') }}</strong>
                <span>{{ __('return_orders.linked_return_orders_hint') }}</span>
            </div>
            <flux:button href="{{ route('return-orders.create', ['issue_id' => $case->id]) }}" wire:navigate>{{ __('return_orders.btn_create') }}</flux:button>
        </div>

        <flux:table class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('return_orders.col_return_no') }}</flux:table.column>
                <flux:table.column>{{ __('return_orders.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('return_orders.field_tracking_no') }}</flux:table.column>
                <flux:table.column>{{ __('common.actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($case->returnOrders as $returnOrder)
                    <flux:table.row :key="$returnOrder->id">
                        <flux:table.cell><strong>{{ $returnOrder->return_no }}</strong></flux:table.cell>
                        <flux:table.cell><flux:badge color="{{ $returnOrder->statusColor() }}">{{ $returnOrder->statusLabel() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $returnOrder->tracking_no ?: '-' }}</flux:table.cell>
                        <flux:table.cell><flux:button size="sm" href="{{ route('return-orders.show', $returnOrder) }}" wire:navigate>{{ __('common.view') }}</flux:button></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4"><div class="empty-state">{{ __('return_orders.no_linked_return_orders') }}</div></flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
    <div class="form-actions">
        <flux:button href="{{ route('issues.index') }}" variant="outline" wire:navigate>{{ __('issues.btn_back') }}</flux:button>
    </div>
</div>

