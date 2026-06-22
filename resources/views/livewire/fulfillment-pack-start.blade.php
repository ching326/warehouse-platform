<div class="fulfillment-pack-start-page">
    <section class="table-shell flux-panel form-panel pack-start-panel">
        <form wire:submit="search" class="pack-scan-form">
            <flux:input
                x-data
                x-init="$nextTick(() => $el.querySelector('input')?.focus())"
                x-on:pack-scan-focus.window="$nextTick(() => $el.querySelector('input')?.focus())"
                wire:model="scan"
                :label="__('fulfillment_pack.scan_tracking_label')"
                :placeholder="__('fulfillment_pack.scan_tracking_placeholder')"
                autocomplete="off"
            />
            <p class="subtle">{{ __('fulfillment_pack.scan_tracking_helper') }}</p>

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

        .pack-feedback.error {
            color: #991b1b;
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .pack-feedback.idle {
            border: 1px solid transparent;
        }
    </style>
</div>
