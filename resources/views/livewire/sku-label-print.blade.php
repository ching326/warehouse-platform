<div class="sku-label-print-page">
    <style>
        .sku-label-print-page .label-inline-actions {
            align-items: end;
            display: flex;
            gap: 10px;
        }

        .sku-label-print-page .label-skip-grid {
            display: grid;
            gap: 6px;
        }

        .sku-label-print-page .label-skip-cell {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 6px;
            color: var(--muted);
            min-height: 34px;
        }

        .sku-label-print-page .label-skip-cell.is-skipped {
            background: #d8f3ef;
            border-color: var(--accent);
            color: var(--accent-strong);
            font-weight: 700;
        }
    </style>

    <x-flash-toast />

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('skus.label_print_title') }}</strong>
                <span>{{ __('skus.label_print_hint') }}</span>
            </div>
            <flux:button href="{{ route('skus.index') }}" variant="outline" wire:navigate>
                {{ __('skus.back_to_skus') }}
            </flux:button>
        </div>

        <div class="form-grid three">
            <flux:select wire:model.live="layoutKey" :label="__('skus.label_layout')">
                @foreach ($layoutOptions as $key => $name)
                    <flux:select.option value="{{ $key }}">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="applyQty" type="number" min="1" step="1" :label="__('skus.label_qty')" />
            <div class="label-inline-actions">
                <label class="inline-check">
                    <input type="checkbox" wire:model="includeName">
                    {{ __('skus.label_include_name') }}
                </label>
                <flux:button type="button" variant="outline" wire:click="applyQtyToAll">
                    {{ __('skus.label_apply_all') }}
                </flux:button>
            </div>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('skus.label_entries') }}</strong>
                <span>{{ __('skus.label_no_content_selected') }}</span>
            </div>
            <flux:button type="button" variant="outline" wire:click="addEntry">
                {{ __('skus.label_add_sku') }}
            </flux:button>
        </div>

        <flux:table class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('skus.col_sku') }}</flux:table.column>
                <flux:table.column>{{ __('skus.label_content') }}</flux:table.column>
                <flux:table.column align="end">{{ __('skus.label_qty') }}</flux:table.column>
                <flux:table.column>{{ __('common.actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($entries as $index => $entry)
                    @php
                        $entrySku = $skus[(int) ($entry['sku_id'] ?? 0)] ?? null;
                        $contentOptions = $entrySku ? $this->contentOptionsFor((int) $entrySku->id) : [];
                    @endphp
                    <flux:table.row :key="'label-entry-'.$index">
                        <flux:table.cell>
                            <strong>{{ $entrySku?->sku ?? '-' }}</strong>
                            <span class="subtle">{{ $entrySku?->displayName() ?: '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <select class="table-control" wire:model="entries.{{ $index }}.content">
                                <option value="">{{ __('skus.label_no_content_selected') }}</option>
                                @foreach ($contentOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error("entries.{$index}.content") <p class="form-error">{{ $message }}</p> @enderror
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <input class="table-control" type="number" min="1" step="1" wire:model="entries.{{ $index }}.qty">
                            @error("entries.{$index}.qty") <p class="form-error">{{ $message }}</p> @enderror
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button type="button" size="xs" variant="subtle" wire:click="removeEntry({{ $index }})">
                                {{ __('skus.btn_remove') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    @if ($layout->supportsSkip())
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('skus.label_skip_cells') }}</strong>
                    <span>{{ __('skus.label_skip_cells_hint') }}</span>
                </div>
            </div>

            <div
                class="label-skip-grid"
                style="grid-template-columns: repeat({{ $layout->cols() }}, minmax(42px, 1fr));"
            >
                @for ($cell = 0; $cell < $layout->cellsPerPage(); $cell++)
                    <button
                        type="button"
                        class="label-skip-cell {{ in_array($cell, array_map('intval', $skipCells), true) ? 'is-skipped' : '' }}"
                        wire:click="toggleSkipCell({{ $cell }})"
                    >
                        {{ $cell + 1 }}
                    </button>
                @endfor
            </div>
        </section>
    @endif

    <div class="form-actions">
        <flux:button type="button" variant="primary" wire:click="generate">
            {{ __('skus.label_generate') }}
        </flux:button>
    </div>
</div>
