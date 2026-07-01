<div class="stock-count-import-page">
    <x-flash-toast />

    @php($stepKeys = ['upload', 'map', 'preview', 'result'])
    @php($currentIdx = array_search($step, $stepKeys, true))
    <div class="import-stepper">
        @foreach (['upload' => __('stock_counts.step_upload'), 'map' => __('stock_counts.step_map'), 'preview' => __('stock_counts.step_preview'), 'result' => __('stock_counts.step_result')] as $stepKey => $stepLabel)
            @php($thisIdx = array_search($stepKey, $stepKeys, true))
            <span @class(['import-step', 'is-active' => $step === $stepKey, 'is-done' => $thisIdx < $currentIdx])>
                <span class="import-step-num">{{ $thisIdx + 1 }}</span>
                {{ $stepLabel }}
            </span>
        @endforeach
    </div>

    @if ($step === 'upload')
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('stock_counts.import_title') }}</strong>
                    <span>{{ __('stock_counts.import_upload_hint') }}</span>
                </div>
                <flux:button href="{{ route('stock-counts.index') }}" variant="outline" wire:navigate>{{ __('stock_counts.btn_back_to_index') }}</flux:button>
            </div>

            <form wire:submit="readFile" class="form-panel">
                <div class="form-grid">
                    @if ($showTenantSelect)
                        <flux:select wire:model.live="tenantId" :label="__('stock_adjustments.field_tenant')" required>
                            <flux:select.option value="">{{ __('skus.select_tenant') }}</flux:select.option>
                            @foreach ($tenants as $tenant)
                                <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <label>
                            <span>{{ __('stock_adjustments.field_tenant') }}</span>
                            <input type="text" value="{{ $currentTenant ? $currentTenant->code.' - '.$currentTenant->name : __('skus.no_active_tenant') }}" readonly>
                        </label>
                    @endif

                    <flux:select wire:model.live="warehouseId" :label="__('stock_adjustments.field_warehouse')" required>
                        <flux:select.option value="">{{ __('stock_adjustments.select_warehouse') }}</flux:select.option>
                        @foreach ($warehouses as $warehouse)
                            <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <flux:textarea wire:model="note" :label="__('stock_adjustments.field_note')" rows="3" />

                <label
                    class="tracking-import-dropzone"
                    x-data="{ dragging: false, fileName: @js($file?->getClientOriginalName() ?? '') }"
                    x-bind:class="{ 'is-dragging': dragging }"
                    x-on:dragover.prevent="dragging = true"
                    x-on:dragleave.prevent="dragging = false"
                    x-on:drop.prevent="
                        dragging = false;
                        const input = $refs.stockCountImportFile;
                        input.files = $event.dataTransfer.files;
                        fileName = input.files.length ? input.files[0].name : '';
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    "
                >
                    <input
                        x-ref="stockCountImportFile"
                        class="tracking-import-file-input"
                        type="file"
                        wire:model="file"
                        accept=".csv,.txt,.xlsx,.xls"
                        x-on:change="fileName = $event.target.files.length ? $event.target.files[0].name : ''"
                    >
                    <strong>{{ __('sku_import.drop_title') }}</strong>
                    <span>{{ __('stock_counts.drop_hint') }}</span>
                    <span class="tracking-import-file-name" x-show="fileName" x-text="fileName"></span>
                    <span class="subtle" wire:loading wire:target="file">...</span>
                </label>
                @error('file') <p class="form-error">{{ $message }}</p> @enderror

                <div class="form-actions">
                    <span></span>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file,readFile">
                        <span wire:loading.remove wire:target="readFile">{{ __('stock_counts.btn_upload') }}</span>
                        <span wire:loading wire:target="readFile">...</span>
                    </flux:button>
                </div>
            </form>
        </section>
    @endif

    @if ($step === 'map')
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('stock_counts.map_templates_heading') }}</strong>
                    @if ($savedTemplates->isEmpty())
                        <span>{{ __('stock_counts.map_no_templates') }}</span>
                    @endif
                </div>
            </div>
            @if ($savedTemplates->isNotEmpty())
                <div class="template-list">
                    @foreach ($savedTemplates as $template)
                        <div class="template-row">
                            <span class="template-name">
                                {{ $template->name }}
                                @if ($template->is_default)
                                    <flux:badge color="blue">{{ __('sku_import.template_default_badge') }}</flux:badge>
                                @endif
                            </span>
                            <div class="template-actions">
                                <flux:button size="sm" wire:click="loadTemplate({{ $template->id }})">{{ __('sku_import.map_btn_load') }}</flux:button>
                                @unless ($template->is_default)
                                    <flux:button size="sm" variant="outline" wire:click="setDefaultTemplate({{ $template->id }})">{{ __('sku_import.map_btn_set_default') }}</flux:button>
                                @endunless
                                <flux:button size="sm" variant="danger" wire:click="deleteTemplate({{ $template->id }})" wire:confirm="{{ __('sku_import.map_btn_delete') }}?">{{ __('sku_import.map_btn_delete') }}</flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('stock_counts.step_map') }}</strong>
                    <span>{{ __('stock_counts.map_hint') }}</span>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table mapping-table">
                    <thead>
                        <tr>
                            <th>{{ __('sku_import.map_col_file_column') }}</th>
                            <th>{{ __('sku_import.map_col_field') }}</th>
                            <th>{{ __('sku_import.map_col_sample') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fileHeaders as $colIdx => $header)
                            @php($mappedField = $columnToField[$header] ?? '')
                            <tr @class(['is-mapped' => $mappedField !== ''])>
                                <td><strong>{{ $header }}</strong></td>
                                <td>
                                    <select class="table-control" wire:change="setFieldForColumn({{ $colIdx }}, $event.target.value)">
                                        <option value="">{{ __('sku_import.map_ignore') }}</option>
                                        @foreach ($fields as $field)
                                            <option value="{{ $field->key }}" @selected($mappedField === $field->key)>{{ __($field->labelKey) }}{{ $field->required ? ' *' : '' }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="map-sample">{{ $sampleRows[0][$colIdx] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="checkbox-stack">
                <label><input type="checkbox" wire:model.live="doSaveTemplate"> {{ __('stock_counts.option_save_template') }}</label>
            </div>
            @if ($doSaveTemplate)
                <flux:input wire:model="templateName" :label="__('sku_import.option_template_name')" :placeholder="__('sku_import.option_template_name')" />
            @endif
        </section>

        <div class="form-actions">
            <flux:button wire:click="backToUpload">{{ __('sku_import.btn_back_to_upload') }}</flux:button>
            <flux:button variant="primary" wire:click="advanceToPreview" wire:loading.attr="disabled" wire:target="advanceToPreview">
                <span wire:loading.remove wire:target="advanceToPreview">{{ __('stock_counts.btn_preview') }}</span>
                <span wire:loading wire:target="advanceToPreview">...</span>
            </flux:button>
        </div>
    @endif

    @if ($step === 'preview')
        <section class="table-shell flux-panel form-panel">
            <div class="import-summary">
                <span class="badge badge-success">{{ __('stock_counts.preview_valid_count', ['count' => $validRowCount]) }}</span>
                @if ($errorRowCount > 0)
                    <span class="badge badge-danger">{{ __('stock_counts.preview_error_count', ['count' => $errorRowCount]) }}</span>
                @endif
                <span class="badge import-badge-total">{{ __('sku_import.preview_total_count', ['count' => $totalDataRows]) }}</span>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('stock_counts.step_preview') }}</strong>
                    <span>{{ __('stock_counts.preview_hint') }}</span>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('sku_import.col_row') }}</th>
                            <th>{{ __('stock_counts.field_identifier') }}</th>
                            <th>{{ __('skus.col_stock_item') }}</th>
                            <th>{{ __('skus.field_tenant_item_code') }}</th>
                            <th>{{ __('skus.col_name') }}</th>
                            <th>{{ __('stock_counts.col_current_on_hand') }}</th>
                            <th>{{ __('stock_adjustments.col_reserved') }}</th>
                            <th>{{ __('stock_adjustments.col_hold') }}</th>
                            <th>{{ __('stock_adjustments.col_damaged') }}</th>
                            <th>{{ __('stock_counts.field_counted_qty') }}</th>
                            <th>{{ __('stock_counts.col_delta') }}</th>
                            <th>{{ __('sku_import.col_status') }}</th>
                            <th>{{ __('sku_import.col_errors') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($previewRows as $previewRow)
                            <tr>
                                <td>{{ $previewRow['row'] }}</td>
                                <td>{{ $previewRow['identifier'] }}</td>
                                <td>{{ $previewRow['stock_item_code'] }}</td>
                                <td>{{ $previewRow['tenant_item_code'] }}</td>
                                <td>{{ $previewRow['stock_item_name'] }}</td>
                                <td>{{ number_format($previewRow['current_on_hand']) }}</td>
                                <td>{{ number_format($previewRow['reserved_qty']) }}</td>
                                <td>{{ number_format($previewRow['hold_qty']) }}</td>
                                <td>{{ number_format($previewRow['damaged_qty']) }}</td>
                                <td>{{ number_format($previewRow['counted_qty']) }}</td>
                                <td>{{ number_format($previewRow['delta_qty']) }}</td>
                                <td>
                                    @if ($previewRow['status'] === 'valid')
                                        <span class="badge badge-success">{{ __('sku_import.status_valid') }}</span>
                                    @else
                                        <span class="badge badge-danger">{{ __('sku_import.status_error') }}</span>
                                    @endif
                                </td>
                                <td>{{ implode(' | ', $previewRow['errors']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="form-actions">
            <flux:button wire:click="backToMap">{{ __('sku_import.btn_back_to_map') }}</flux:button>
            <flux:button variant="primary" wire:click="confirmImport" wire:loading.attr="disabled" wire:target="confirmImport" :disabled="$validRowCount === 0 || $errorRowCount > 0">
                <span wire:loading.remove wire:target="confirmImport">{{ __('stock_counts.btn_confirm') }}</span>
                <span wire:loading wire:target="confirmImport">...</span>
            </flux:button>
        </div>
    @endif

    @if ($step === 'result')
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <strong>{{ __('stock_counts.result_heading') }}</strong>
            </div>
            <div class="import-stats">
                <div class="import-stat is-created"><strong>{{ $resultAdjusted }}</strong><span>{{ __('stock_counts.result_adjusted', ['count' => $resultAdjusted]) }}</span></div>
                <div class="import-stat is-updated"><strong>{{ $resultNoChange }}</strong><span>{{ __('stock_counts.result_no_change', ['count' => $resultNoChange]) }}</span></div>
                <div class="import-stat is-skipped"><strong>{{ $resultTotal }}</strong><span>{{ __('stock_counts.result_total', ['count' => $resultTotal]) }}</span></div>
            </div>
            <div class="form-actions">
                <span></span>
                <div class="template-actions">
                    @if ($resultRunId)
                        <flux:button href="{{ route('stock-counts.show', $resultRunId) }}" variant="primary" wire:navigate>{{ __('stock_counts.btn_view_run') }}</flux:button>
                    @endif
                    <flux:button href="{{ route('stock-counts.index') }}" variant="primary" wire:navigate>{{ __('stock_counts.btn_back_to_index') }}</flux:button>
                </div>
            </div>
        </section>
    @endif
</div>
