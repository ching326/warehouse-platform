<div class="inbound-receive-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('inbound.section_order_summary') }}</strong>
                </div>
                <flux:button href="{{ route('inbound.index') }}" variant="outline">{{ __('inbound.btn_back') }}</flux:button>
            </div>

            <div class="balance-preview-grid">
                <div>
                    <span>{{ __('inbound.field_tenant') }}</span>
                    <strong>{{ $order->tenant->code }}</strong>
                </div>
                <div>
                    <span>{{ __('inbound.field_warehouse') }}</span>
                    <strong>{{ $order->warehouse->code }}</strong>
                </div>
                <div>
                    <span>{{ __('inbound.field_ref') }}</span>
                    <strong>{{ $order->ref ?: '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('inbound.field_expected_at') }}</span>
                    <strong>{{ $order->expected_at ? $order->expected_at->format('Y-m-d') : '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('inbound.col_status') }}</span>
                    <strong>{{ __('inbound.status_'.$order->status) }}</strong>
                </div>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('inbound.section_receive_lines') }}</strong>
                    <span>{{ __('inbound.section_receive_hint') }}</span>
                </div>
            </div>

            @php
                $openLines = $order->lines->filter(fn ($line) => $line->received_qty < $line->expected_qty);
            @endphp

            @forelse ($openLines as $line)
                @php
                    $remaining = $line->expected_qty - $line->received_qty;
                @endphp
                <div class="receive-line-panel">
                    <div class="form-panel-header">
                        <div>
                            <strong>{{ $line->sku->sku }} - {{ $line->sku->name }}</strong>
                            <span>{{ $line->stockItem->code }} - {{ $line->stockItem->name }}</span>
                        </div>
                    </div>

                    <div class="balance-preview-grid">
                        <div>
                            <span>{{ __('inbound.field_expected_qty') }}</span>
                            <strong>{{ number_format($line->expected_qty) }}</strong>
                        </div>
                        @if ($line->received_qty > 0)
                            <div>
                                <span>{{ __('inbound.field_already_received') }}</span>
                                <strong>{{ number_format($line->received_qty) }}</strong>
                            </div>
                        @endif
                        <div>
                            <span>{{ __('inbound.field_remaining_qty') }}</span>
                            <strong>{{ number_format($remaining) }}</strong>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <flux:input
                                type="number"
                                wire:model.live="lineInputs.{{ $line->id }}.actual_qty"
                                min="0"
                                step="1"
                                :label="__('inbound.field_actual_qty')"
                            />
                            <span class="subtle">{{ __('inbound.field_actual_qty_hint') }}</span>
                        </div>

                        <flux:select wire:model="lineInputs.{{ $line->id }}.location_id" :label="__('inbound.field_receiving_location')">
                            <flux:select.option value="">{{ __('inbound.select_location') }}</flux:select.option>
                            @foreach ($locationOptions as $location)
                                <flux:select.option value="{{ $location->id }}">{{ $location->code }} - {{ $location->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    @error("lineInputs.{$line->id}.actual_qty") <p class="form-error">{{ $message }}</p> @enderror
                    @error("lineInputs.{$line->id}.location_id") <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @empty
                <span class="subtle">{{ __('inbound.all_lines_received') }}</span>
            @endforelse

            @error('lineInputs') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('inbound.index') }}" variant="outline">{{ __('inbound.btn_cancel') }}</flux:button>
            @if ($openLines->isNotEmpty())
                <flux:button type="submit" variant="primary">{{ __('inbound.btn_submit_receive') }}</flux:button>
            @endif
        </div>
    </form>
</div>
