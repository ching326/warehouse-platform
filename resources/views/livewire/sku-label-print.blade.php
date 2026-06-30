<div class="sku-label-print-page">
    <x-flash-toast />

    <section class="table-shell flux-panel form-panel sku-label-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('skus.label_print_title') }}</strong>
                <span>{{ __('skus.label_print_hint') }}</span>
            </div>
            <flux:button href="{{ route('skus.index') }}" variant="outline" wire:navigate>
                {{ __('skus.back_to_skus') }}
            </flux:button>
        </div>

        <div class="sku-label-entry-header">
            <span>{{ __('skus.label_fnsku') }}</span>
            <span>{{ __('skus.label_product_name') }}</span>
            <span>{{ __('skus.label_type') }}</span>
            <span>{{ __('skus.label_qty') }}</span>
            <span></span>
        </div>

        <div class="sku-label-entry-list">
            @foreach ($entries as $index => $entry)
                @php
                    $entrySku = $skus[(int) ($entry['sku_id'] ?? 0)] ?? null;
                    $contentOptions = $entrySku ? $this->contentOptionsFor((int) $entrySku->id) : [];
                @endphp

                <div class="sku-label-entry-row" wire:key="label-entry-{{ $index }}">
                    <div>
                        <input
                            class="table-control"
                            type="text"
                            wire:model="entries.{{ $index }}.value"
                            aria-label="{{ __('skus.label_fnsku') }}"
                        >
                        @error("entries.{$index}.value") <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <input
                            class="table-control"
                            type="text"
                            wire:model="entries.{{ $index }}.name"
                            aria-label="{{ __('skus.label_product_name') }}"
                        >
                        @error("entries.{$index}.name") <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <select class="table-control" wire:model.live="entries.{{ $index }}.content">
                            @foreach ($contentOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error("entries.{$index}.content") <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <input
                            class="table-control"
                            type="number"
                            min="1"
                            step="1"
                            wire:model="entries.{{ $index }}.qty"
                            aria-label="{{ __('skus.label_qty') }}"
                        >
                        @error("entries.{$index}.qty") <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="sku-label-row-action">
                        <button
                            type="button"
                            class="remove-line-btn"
                            wire:click="removeEntry({{ $index }})"
                            aria-label="{{ __('common.remove') }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="sku-label-row-tools">
            <flux:button type="button" variant="primary" wire:click="addEntry">
                {{ __('skus.label_add_row') }}
            </flux:button>

            <div class="sku-label-apply-qty">
                <flux:input wire:model="applyQty" type="number" min="1" step="1" :label="__('skus.label_apply_qty')" />
                <flux:button type="button" variant="primary" wire:click="applyQtyToAll">
                    {{ __('skus.label_apply_all') }}
                </flux:button>
            </div>
        </div>

        <div class="sku-label-layout-row">
            <flux:select wire:model.live="layoutKey" :label="__('skus.label_layout')">
                @foreach ($layoutOptions as $key => $name)
                    <flux:select.option value="{{ $key }}">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="sku-label-generate-row">
            <flux:button type="button" variant="primary" wire:click="generate">
                {{ __('skus.label_generate') }}
            </flux:button>

            @if ($layout->supportsSkip())
                <div class="sku-label-skip-control">
                    <label class="inline-check">
                        <input type="checkbox" @checked($useSkipCells) wire:click="toggleSkipCellsMode">
                        {{ __('skus.label_skip_used_cells') }}
                    </label>

                    @if ($useSkipCells)
                        <button type="button" class="sku-label-skip-edit" wire:click="$set('showSkipCellsModal', true)">
                            {{ __('skus.label_skip_cells_edit') }}
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </section>

    @if ($showSkipCellsModal && $layout->supportsSkip())
        <div class="image-panel-backdrop app-modal-backdrop">
            <section class="image-panel app-modal-panel tracking-import-modal flux-panel sku-label-skip-modal" style="--app-modal-width: 760px;" aria-label="{{ __('skus.label_skip_modal_title') }}">
                <header class="tracking-import-header">
                    <div>
                        <h2>{{ __('skus.label_skip_modal_title') }}</h2>
                        <p>{{ __('skus.label_skip_modal_hint') }}</p>
                    </div>
                    <button type="button" class="modal-icon-close" wire:click="closeSkipCellsModal" aria-label="{{ __('common.cancel') }}">&times;</button>
                </header>

                <div class="sku-label-skip-grid" style="grid-template-columns: repeat({{ $layout->cols() }}, minmax(0, 1fr));">
                    @for ($cell = 0; $cell < $layout->cellsPerPage(); $cell++)
                        @php
                            $isSkipped = in_array($cell, array_map('intval', $skipCells), true);
                        @endphp
                        <label class="sku-label-print-cell {{ $isSkipped ? 'is-skipped' : '' }}">
                            <input
                                type="checkbox"
                                @checked(! $isSkipped)
                                wire:click="toggleSkipCell({{ $cell }})"
                            >
                            <span>{{ __('skus.label_print_cell') }}</span>
                        </label>
                    @endfor
                </div>

                <footer class="tracking-import-footer">
                    <flux:button type="button" variant="primary" wire:click="closeSkipCellsModal">
                        {{ __('skus.label_skip_modal_done') }}
                    </flux:button>
                </footer>
            </section>
        </div>
    @endif

    <style>
        .sku-label-panel {
            display: grid;
            gap: 16px;
        }

        .sku-label-entry-header,
        .sku-label-entry-row {
            display: grid;
            grid-template-columns: minmax(150px, 1.1fr) minmax(260px, 2.2fr) minmax(180px, 1fr) 86px 92px;
            gap: 12px;
            align-items: start;
        }

        .sku-label-entry-header {
            color: var(--ink);
            font-size: 13px;
            font-weight: 700;
        }

        .sku-label-entry-list {
            display: grid;
            gap: 10px;
        }

        .sku-label-row-action {
            display: flex;
            justify-content: flex-end;
        }

        .sku-label-row-tools {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            padding-top: 2px;
        }

        .sku-label-apply-qty {
            display: grid;
            grid-template-columns: 120px auto;
            gap: 10px;
            align-items: end;
        }

        .sku-label-layout-row {
            max-width: 440px;
            padding-top: 6px;
        }

        .sku-label-generate-row {
            display: grid;
            justify-items: end;
            gap: 10px;
            border-top: 1px solid var(--line);
            padding-top: 16px;
        }

        .sku-label-skip-control {
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: flex-end;
        }

        .sku-label-skip-edit {
            border: 0;
            background: transparent;
            color: var(--accent);
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            padding: 0;
        }

        .sku-label-skip-modal {
            max-height: calc(100vh - 96px);
            overflow: auto;
        }

        .sku-label-skip-grid {
            display: grid;
            gap: 8px;
            margin: 18px 0;
        }

        .sku-label-print-cell {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--muted);
            font-size: 13px;
            cursor: pointer;
        }

        .sku-label-print-cell.is-skipped {
            background: #f8fafc;
            color: #94a3b8;
        }

        @media (max-width: 980px) {
            .sku-label-entry-header {
                display: none;
            }

            .sku-label-entry-row {
                grid-template-columns: 1fr;
                border-bottom: 1px solid var(--line);
                padding-bottom: 12px;
            }

            .sku-label-row-action {
                justify-content: flex-start;
            }
        }
    </style>
</div>
