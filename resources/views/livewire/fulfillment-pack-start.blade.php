<div class="fulfillment-pack-start-page">
    <section class="table-shell flux-panel form-panel pack-start-panel">
        <form wire:submit="search" class="pack-scan-form">
            <div class="pack-station-grid">
                <flux:select wire:model.live="warehouseId" :label="__('fulfillment_pack.warehouse_label')">
                    <flux:select.option value="">{{ __('fulfillment_pack.select_warehouse') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="shippingMethodId" :label="__('fulfillment_pack.shipping_method_label')">
                    <flux:select.option value="">{{ __('fulfillment_pack.select_shipping_method') }}</flux:select.option>
                    @foreach ($shippingMethods as $method)
                        <flux:select.option value="{{ $method->id }}">
                            {{ $method->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input
                x-data
                x-init="$nextTick(() => {{ $filtersReady ? '$el.querySelector(\'input\')?.focus()' : 'null' }})"
                x-on:pack-scan-focus.window="$nextTick(() => $el.querySelector('input')?.focus())"
                wire:model="scan"
                :label="__('fulfillment_pack.scan_tracking_label')"
                :placeholder="__('fulfillment_pack.scan_tracking_placeholder')"
                autocomplete="off"
                :disabled="! $filtersReady"
            />
            <p class="subtle">{{ __('fulfillment_pack.scan_tracking_helper') }}</p>
            @if ($lastScan)
                <p class="pack-last-scan">{{ __('fulfillment_pack.last_scan', ['scan' => $lastScan]) }}</p>
            @endif

            @if ($message)
                <div class="pack-feedback error">{{ $message }}</div>
            @else
                <div class="pack-feedback idle">&nbsp;</div>
            @endif
        </form>
    </section>

    <style>
        .pack-start-panel {
            max-width: 760px;
            margin: 0 auto;
        }

        .pack-scan-form {
            display: grid;
            gap: 10px;
        }

        .pack-station-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .pack-scan-form input {
            min-height: 52px;
            font-size: 20px;
            font-weight: 700;
        }

        .pack-feedback {
            min-height: 44px;
            border-radius: 8px;
            padding: 12px 14px;
            font-weight: 700;
        }

        .pack-last-scan {
            min-height: 20px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .pack-feedback.error {
            color: #991b1b;
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .pack-feedback.idle {
            border: 1px solid transparent;
        }

        @media (max-width: 720px) {
            .pack-station-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</div>
