<div class="stock-adjustment-import-page">
    <x-flash-toast />

    @if ($pendingErrorImportWarning)
        <div class="app-toast app-toast-warning app-toast-confirm" role="alert">
            <div class="app-toast-body">
                <strong class="app-toast-title">{{ __('common.toast.warning') }}</strong>
                <span class="app-toast-text">{{ $pendingErrorImportWarning }}</span>
                <div class="app-toast-actions">
                    <flux:button type="button" size="sm" variant="outline" wire:click="cancelImportWithErrors">
                        {{ __('common.cancel') }}
                    </flux:button>
                    <flux:button type="button" size="sm" variant="primary" wire:click="confirmImportWithErrors">
                        {{ __('stock_adjustment_import.btn_import_valid_rows') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    @php($stepKeys = ['upload', 'map', 'preview', 'result'])
    @php($currentIdx = array_search($step, $stepKeys, true))
    <div class="import-stepper">
        @foreach (['upload' => __('stock_adjustment_import.step_upload'), 'map' => __('stock_adjustment_import.step_map'), 'preview' => __('stock_adjustment_import.step_preview'), 'result' => __('stock_adjustment_import.step_result')] as $stepKey => $stepLabel)
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
                    <strong>{{ __('stock_adjustment_import.upload_heading') }}</strong>
                    <span>{{ __('stock_adjustment_import.upload_hint') }}</span>
                </div>
                <flux:button href="{{ route('stock-adjustments.create') }}" variant="outline" wire:navigate>{{ __('stock_adjustment_import.btn_back_to_adjustment') }}</flux:button>
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

                    <flux:select wire:model.live="action" :label="__('stock_adjustments.field_action')" required>
                        <flux:select.option value="">{{ __('stock_adjustments.select_action') }}</flux:select.option>
                        @foreach ($actionOptions as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="reason" :label="__('stock_adjustments.field_reason')" :disabled="$action === ''" required>
                        <flux:select.option value="">{{ __('stock_adjustments.select_reason') }}</flux:select.option>
                        @foreach ($reasonOptions as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
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
                        const input = $refs.stockAdjustmentImportFile;
                        input.files = $event.dataTransfer.files;
                        fileName = input.files.length ? input.files[0].name : '';
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    "
                >
                    <input
                        x-ref="stockAdjustmentImportFile"
                        class="tracking-import-file-input"
                        type="file"
                        wire:model="file"
                        accept=".csv,.txt,.xlsx,.xls"
                        x-on:change="fileName = $event.target.files.length ? $event.target.files[0].name : ''"
                    >
                    <strong>{{ __('sku_import.drop_title') }}</strong>
                    <span>{{ __('stock_adjustment_import.drop_hint') }}</span>
                    <span class="tracking-import-file-name" x-show="fileName" x-text="fileName"></span>
                    <span class="subtle" wire:loading wire:target="file">...</span>
                </label>
                @error('file') <p class="form-error">{{ $message }}</p> @enderror

                <div class="form-actions">
                    <span></span>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file,readFile">
                        <span wire:loading.remove wire:target="readFile">{{ __('stock_adjustment_import.btn_upload') }}</span>
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
                    <strong>{{ __('stock_adjustment_import.map_templates_heading') }}</strong>
                    @if ($savedTemplates->isEmpty())
                        <span>{{ __('stock_adjustment_import.map_no_templates') }}</span>
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
                    <strong>{{ __('stock_adjustment_import.map_heading') }}</strong>
                    <span>{{ __('stock_adjustment_import.map_hint') }}</span>
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
                            @php($sampleValue = $sampleRows[0][$colIdx] ?? '')
                            <tr @class(['is-mapped' => $mappedField !== ''])>
                                <td><strong>{{ $header }}</strong></td>
                                <td>
                                    <select class="table-control" wire:change="setFieldForColumn({{ $colIdx }}, $event.target.value)">
                                        <option value="">{{ __('sku_import.map_ignore') }}</option>
                                        @foreach ($fields as $field)
                                            <option value="{{ $field->key }}" @selected($mappedField === $field->key)>
                                                {{ __($field->labelKey) }}{{ $field->required ? ' *' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="map-sample">{{ $sampleValue }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @php($mappedFields = array_filter($fields, fn ($field) => ($mapping[$field->key] ?? '') !== ''))
        @if (! empty($mappedFields) && ! empty($sampleRows))
            <section class="table-shell flux-panel form-panel">
                <div class="form-panel-header">
                    <strong>{{ __('stock_adjustment_import.map_sample_heading') }}</strong>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                @foreach ($mappedFields as $field)
                                    <th>{{ __($field->labelKey) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sampleRows as $row)
                                <tr>
                                    @foreach ($mappedFields as $field)
                                        @php($colIdx = array_search($mapping[$field->key], $fileHeaders, true))
                                        <td>{{ $colIdx !== false ? ($row[$colIdx] ?? '') : '' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="table-shell flux-panel form-panel">
            <div class="checkbox-stack">
                <label><input type="checkbox" wire:model.live="doSaveTemplate"> {{ __('sku_import.option_save_template') }}</label>
            </div>
            @if ($doSaveTemplate)
                <flux:input wire:model="templateName" :label="__('sku_import.option_template_name')" :placeholder="__('sku_import.option_template_name')" />
                <div class="checkbox-stack">
                    <label><input type="checkbox" wire:model.live="templateAsDefault"> {{ __('sku_import.option_save_template_as_default') }}</label>
                </div>
                <div class="form-actions">
                    <span></span>
                    <flux:button type="button" variant="primary" wire:click="saveTemplate">{{ __('stock_adjustment_import.btn_save_template') }}</flux:button>
                </div>
            @endif
        </section>

        <div class="form-actions">
            <flux:button wire:click="backToUpload">{{ __('sku_import.btn_back_to_upload') }}</flux:button>
            <flux:button variant="primary" wire:click="advanceToPreview" wire:loading.attr="disabled" wire:target="advanceToPreview">
                <span wire:loading.remove wire:target="advanceToPreview">{{ __('stock_adjustment_import.btn_preview') }}</span>
                <span wire:loading wire:target="advanceToPreview">...</span>
            </flux:button>
        </div>
    @endif

    @if ($step === 'preview')
        <section class="table-shell flux-panel form-panel">
            <div class="import-summary">
                <span class="badge badge-success">{{ __('stock_adjustment_import.preview_valid_count', ['count' => $validRowCount]) }}</span>
                @if ($errorRowCount > 0)
                    <span class="badge badge-danger">{{ __('stock_adjustment_import.preview_error_count', ['count' => $errorRowCount]) }}</span>
                @endif
                <span class="badge import-badge-total">{{ __('sku_import.preview_total_count', ['count' => $totalDataRows]) }}</span>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('stock_adjustment_import.preview_heading') }}</strong>
                    <span>{{ __('stock_adjustment_import.preview_hint') }}</span>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table stock-adjustment-preview-table">
                    <thead>
                        <tr>
                            <th>{{ __('sku_import.col_row') }}</th>
                            <th>{{ __('stock_adjustment_import.field_identifier') }}</th>
                            <th>{{ __('skus.col_stock_item') }}</th>
                            <th>{{ __('stock_adjustment_import.col_current_on_hand') }}</th>
                            <th>{{ __('stock_adjustment_import.field_quantity') }}</th>
                            <th>{{ __('stock_adjustment_import.col_resulting_on_hand') }}</th>
                            <th>{{ __('stock_adjustments.field_reason') }}</th>
                            <th>{{ __('stock_adjustment_import.col_note_ref') }}</th>
                            <th>{{ __('sku_import.col_status') }}</th>
                            <th>{{ __('sku_import.col_errors') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($previewRows as $previewRow)
                            <tr>
                                <td>{{ $previewRow['row'] }}</td>
                                <td>{{ $previewRow['identifier'] }}</td>
                                <td>
                                    <strong>{{ $previewRow['stock_item_code'] ?: '-' }}</strong>
                                    @if ($previewRow['tenant_item_code'] !== '')
                                        <span class="subtle">{{ $previewRow['tenant_item_code'] }}</span>
                                    @endif
                                    @if ($previewRow['stock_item_name'] !== '')
                                        <span class="subtle">{{ $previewRow['stock_item_name'] }}</span>
                                    @endif
                                </td>
                                <td>{{ number_format($previewRow['current_on_hand']) }}</td>
                                <td>{{ number_format($previewRow['quantity']) }}</td>
                                <td>{{ number_format($previewRow['resulting_on_hand']) }}</td>
                                <td>{{ $reason !== '' ? __('stock_adjustments.reasons.'.$reason) : '-' }}</td>
                                <td>{{ collect([$previewRow['line_note'], $previewRow['reference_no']])->filter()->implode(' / ') }}</td>
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
            <flux:button
                variant="primary"
                wire:click="confirmImport"
                wire:loading.attr="disabled"
                wire:target="confirmImport"
                :disabled="$validRowCount === 0"
            >
                <span wire:loading.remove wire:target="confirmImport">{{ __('stock_adjustment_import.btn_confirm') }}</span>
                <span wire:loading wire:target="confirmImport">...</span>
            </flux:button>
        </div>
    @endif

    @if ($step === 'result')
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <strong>{{ __('stock_adjustment_import.result_heading') }}</strong>
            </div>

            <div class="import-stats">
                <div class="import-stat is-created">
                    <strong>{{ $resultAdjusted }}</strong>
                    <span>{{ __('stock_adjustment_import.result_adjusted', ['count' => $resultAdjusted]) }}</span>
                </div>
                <div class="import-stat is-skipped">
                    <strong>{{ $resultTotal }}</strong>
                    <span>{{ __('stock_adjustment_import.result_total', ['count' => $resultTotal]) }}</span>
                </div>
                @if ($resultFailed > 0)
                    <div class="import-stat is-failed">
                        <strong>{{ $resultFailed }}</strong>
                        <span>{{ __('sku_import.result_failed', ['count' => $resultFailed]) }}</span>
                    </div>
                @endif
            </div>

            <div class="form-actions">
                <span></span>
                <div class="template-actions">
                    <flux:button href="{{ route('inventory.index') }}" variant="primary" wire:navigate>{{ __('stock_adjustment_import.btn_view_inventory') }}</flux:button>
                    <flux:button href="{{ route('stock-adjustments.create') }}" variant="primary" wire:navigate>{{ __('stock_adjustment_import.btn_new_adjustment') }}</flux:button>
                    <flux:button variant="primary" wire:click="startOver">{{ __('stock_adjustment_import.btn_import_more') }}</flux:button>
                </div>
            </div>
        </section>
    @endif
</div>
