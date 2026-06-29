<div class="inbound-detail-page">
    <x-flash-toast />
<section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ $order->ref ?: '-' }}</strong>
                <span>#{{ $order->id }} / {{ $order->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="active-filter-row">
                <x-status-badge :status="$order->status" :label="$this->statusLabel($order->status)" />
                <flux:button href="{{ route('inbound.index') }}" variant="outline" wire:navigate>
                    {{ __('inbound.btn_back_to_index') }}
                </flux:button>
            </div>
        </div>

        <div class="balance-preview-grid">
            <div>
                <span>{{ __('inbound.field_tenant') }}</span>
                <strong>{{ $order->tenant->code }} - {{ $order->tenant->name }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.field_warehouse') }}</span>
                <strong>{{ $order->warehouse->code }} - {{ $order->warehouse->name }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.field_expected_at') }}</span>
                <strong>{{ $order->expected_at ? $order->expected_at->format('Y-m-d') : '-' }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.created_at') }}</span>
                <strong>{{ $order->created_at->format('Y-m-d H:i') }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.created_by') }}</span>
                <strong>{{ $order->createdBy?->name ?: '-' }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.arrived_at') }}</span>
                <strong>{{ $order->arrived_at ? $order->arrived_at->format('Y-m-d H:i') : '-' }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.arrived_by') }}</span>
                <strong>{{ $order->arrivedBy?->name ?: '-' }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.received_at') }}</span>
                <strong>{{ $order->received_at ? $order->received_at->format('Y-m-d H:i') : '-' }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.received_by') }}</span>
                <strong>{{ $order->receivedBy?->name ?: '-' }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.field_expected_carton_count') }}</span>
                <strong>{{ $order->expected_carton_count !== null ? number_format($order->expected_carton_count) : '-' }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.field_received_carton_count') }}</span>
                <strong>{{ $order->received_carton_count !== null ? number_format($order->received_carton_count) : '-' }}</strong>
            </div>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('inbound.section_actions') }}</strong>
                <span>{{ __('inbound.detail_page_subtitle') }}</span>
            </div>
        </div>

        @if ($order->status === \App\Models\InboundOrder::STATUS_PENDING)
            <div class="form-actions inbound-detail-actions">
                <flux:button
                    type="button"
                    variant="primary"
                    wire:click="markArrived"
                    wire:confirm="{{ __('inbound.confirm_arrive') }}"
                >
                    {{ __('inbound.btn_mark_arrived') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="danger"
                    wire:click="cancel"
                    wire:confirm="{{ __('inbound.confirm_cancel') }}"
                >
                    {{ __('inbound.btn_cancel_order') }}
                </flux:button>
            </div>
        @elseif ($this->canReceive($order))
            <div class="form-actions inbound-detail-actions">
                <flux:button href="{{ route('inbound.receive', $order) }}" variant="primary" wire:navigate>
                    {{ __('inbound.btn_receive') }}
                </flux:button>
                @if ($this->canCancel($order))
                    <flux:button
                        type="button"
                        variant="danger"
                        wire:click="cancel"
                        wire:confirm="{{ __('inbound.confirm_cancel') }}"
                    >
                        {{ __('inbound.btn_cancel_order') }}
                    </flux:button>
                @endif
            </div>
        @else
            <span class="muted-dash">-</span>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('inbound.section_metadata') }}</strong>
            </div>
        </div>

        <div class="balance-preview-grid">
            <div>
                <span>{{ __('inbound.field_ref') }}</span>
                <strong>{{ $order->ref ?: '-' }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.field_expected_at') }}</span>
                <strong>{{ $order->expected_at ? $order->expected_at->format('Y-m-d') : '-' }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.field_note') }}</span>
                <strong>{{ $order->note ?: '-' }}</strong>
            </div>
            <div>
                <span>{{ __('inbound.field_carton_mark') }}</span>
                <strong>{{ $order->carton_mark ?: '-' }}</strong>
            </div>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('inbound.section_documents') }}</strong>
                <span>{{ __('inbound.section_documents_hint') }}</span>
            </div>
        </div>

        <div class="inbound-document-upload">
            <label>
                <span>{{ __('inbound.field_document') }}</span>
                <input type="file" wire:model="document" accept=".pdf,.jpg,.jpeg,.png,.webp">
            </label>
            <flux:button type="button" variant="primary" wire:click="uploadDocument" wire:loading.attr="disabled" wire:target="document,uploadDocument">
                {{ __('inbound.btn_upload_document') }}
            </flux:button>
        </div>
        @error('document') <p class="form-error">{{ $message }}</p> @enderror

        <div class="inbound-document-list">
            @forelse ($order->mediaAssets as $asset)
                <div class="inbound-document-row" wire:key="inbound-document-{{ $asset->id }}">
                    <a href="{{ $asset->url() }}" target="_blank" rel="noopener">
                        {{ $asset->file_name }}
                    </a>
                    <flux:button
                        type="button"
                        variant="danger"
                        size="sm"
                        wire:click="deleteDocument({{ $asset->id }})"
                        wire:confirm="{{ __('inbound.confirm_delete_document') }}"
                    >
                        {{ __('inbound.btn_remove_line') }}
                    </flux:button>
                </div>
            @empty
                <span class="subtle">{{ __('inbound.no_documents') }}</span>
            @endforelse
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('inbound.section_lines') }}</strong>
            </div>
        </div>

        <flux:table class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('inbound.field_sku') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.stock_item') }}</flux:table.column>
                <flux:table.column align="end">{{ __('inbound.field_expected_qty') }}</flux:table.column>
                <flux:table.column align="end">{{ __('inbound.field_received_qty') }}</flux:table.column>
                <flux:table.column align="end">{{ __('inbound.field_remaining_qty') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.field_line_note') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($order->lines as $line)
                    <flux:table.row :key="$line->id">
                        <flux:table.cell>
                            <strong>{{ $line->sku?->sku ?: '-' }}</strong>
                            <span class="subtle">{{ $line->sku?->name ?: '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $line->stockItem?->code ?: '-' }}</strong>
                            <span class="subtle">{{ $line->stockItem?->name ?: '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line->expected_qty) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line->received_qty) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line->expected_qty - $line->received_qty) }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $line->note ?: '-' }}
                            @if ($line->receipts->isNotEmpty())
                                <div class="inbound-receipt-list">
                                    @foreach ($line->receipts as $receipt)
                                        <span>
                                            {{ number_format($receipt->received_qty) }}
                                            @if ($receipt->warehouseLocation)
                                                / {{ $receipt->warehouseLocation->code }}
                                            @endif
                                            @if ($receipt->received_at)
                                                / {{ $receipt->received_at->format('Y-m-d H:i') }}
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .inbound-detail-actions {
            justify-content: space-between;
        }

        .inbound-receipt-list {
            display: grid;
            gap: 2px;
            margin-top: 6px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
        }

        .inbound-document-upload {
            display: flex;
            align-items: end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .inbound-document-upload label {
            min-width: min(360px, 100%);
        }

        .inbound-document-list {
            display: grid;
            gap: 8px;
            margin-top: 14px;
        }

        .inbound-document-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 6px;
        }
    </style>
</div>
