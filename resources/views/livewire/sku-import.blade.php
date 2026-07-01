<div class="sku-import-page">
    <x-flash-toast />

    {{-- Step indicator --}}
    @php($stepKeys = ['upload', 'map', 'preview', 'result'])
    @php($currentIdx = array_search($step, $stepKeys, true))
    <div class="import-stepper">
        @foreach (['upload' => __('sku_import.step_upload'), 'map' => __('sku_import.step_map'), 'preview' => __('sku_import.step_preview'), 'result' => __('sku_import.step_result')] as $stepKey => $stepLabel)
            @php($thisIdx = array_search($stepKey, $stepKeys, true))
            <span @class(['import-step', 'is-active' => $step === $stepKey, 'is-done' => $thisIdx < $currentIdx])>
                <span class="import-step-num">{{ $thisIdx + 1 }}</span>
                {{ $stepLabel }}
            </span>
        @endforeach
    </div>

    {{-- Step 1: Upload --}}
    @if ($step === 'upload')
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sku_import.page_title') }}</strong>
                    <span>{{ __('sku_import.upload_hint') }}</span>
                </div>
                <flux:button href="{{ route('skus.index') }}" variant="outline" wire:navigate>{{ __('skus.btn_back') }}</flux:button>
            </div>

            <form wire:submit="readFile" class="form-panel">
                <div class="form-grid">
                    @if ($showTenantSelect)
                        <flux:select wire:model.live="tenantId" :label="__('skus.field_tenant')" required>
                            <flux:select.option value="">{{ __('skus.select_tenant') }}</flux:select.option>
                            @foreach ($tenants as $tenant)
                                <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:select wire:model.live="shopId" :label="__('skus.field_shop')" required>
                        <flux:select.option value="">{{ __('skus.select_shop') }}</flux:select.option>
                        @foreach ($shops as $shop)
                            <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <label
                    class="tracking-import-dropzone"
                    x-data="{ dragging: false, fileName: @js($file?->getClientOriginalName() ?? '') }"
                    x-bind:class="{ 'is-dragging': dragging }"
                    x-on:dragover.prevent="dragging = true"
                    x-on:dragleave.prevent="dragging = false"
                    x-on:drop.prevent="
                        dragging = false;
                        const input = $refs.skuImportFile;
                        input.files = $event.dataTransfer.files;
                        fileName = input.files.length ? input.files[0].name : '';
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    "
                >
                    <input
                        x-ref="skuImportFile"
                        class="tracking-import-file-input"
                        type="file"
                        wire:model="file"
                        accept=".csv,.txt,.xlsx,.xls"
                        x-on:change="fileName = $event.target.files.length ? $event.target.files[0].name : ''"
                    >
                    <strong>{{ __('sku_import.drop_title') }}</strong>
                    <span>{{ __('sku_import.drop_hint') }}</span>
                    <span class="tracking-import-file-name" x-show="fileName" x-text="fileName"></span>
                    <span class="subtle" wire:loading wire:target="file">...</span>
                </label>
                @error('file') <p class="form-error">{{ $message }}</p> @enderror

                <div class="form-actions">
                    <span></span>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file,readFile">
                        <span wire:loading.remove wire:target="readFile">{{ __('sku_import.btn_upload') }}</span>
                        <span wire:loading wire:target="readFile">...</span>
                    </flux:button>
                </div>
            </form>
        </section>
    @endif

    {{-- Step 2: Map fields --}}
    @if ($step === 'map')
        {{-- Saved templates --}}
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sku_import.map_templates_heading') }}</strong>
                    @if ($savedTemplates->isEmpty())
                        <span>{{ __('sku_import.map_no_templates') }}</span>
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

        {{-- Column-to-field mapping --}}
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sku_import.map_heading') }}</strong>
                    <span>{{ __('sku_import.map_hint') }}</span>
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
                                        <optgroup label="{{ __('sku_import.map_group_sku') }}">
                                            @foreach ($fields as $field)
                                                @if ($field->target === 'sku')
                                                    <option value="{{ $field->key }}" @selected($mappedField === $field->key)>{{ __($field->labelKey) }}{{ ($field->required || $field->key === 'name') ? ' *' : '' }}</option>
                                                @endif
                                            @endforeach
                                        </optgroup>
                                        <optgroup label="{{ __('sku_import.map_group_stock_item') }}">
                                            @foreach ($fields as $field)
                                                @if ($field->target === 'stock_item')
                                                    <option value="{{ $field->key }}" @selected($mappedField === $field->key)>{{ __($field->labelKey) }}</option>
                                                @endif
                                            @endforeach
                                        </optgroup>
                                    </select>

                                    @if ($mappedField === 'barcode' && $needsDefaultBarcodeType)
                                        <div class="mapping-inline-option">
                                            <label for="sku-import-default-barcode-type-{{ $colIdx }}">{{ __('sku_import.default_barcode_type') }} <span class="required-indicator">*</span></label>
                                            <select id="sku-import-default-barcode-type-{{ $colIdx }}" class="table-control" wire:model.live="defaultBarcodeType">
                                                <option value="">{{ __('sku_import.default_barcode_type_placeholder') }}</option>
                                                @foreach ($barcodeTypeOptions as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <span>{{ __('sku_import.default_barcode_type_hint') }}</span>
                                        </div>
                                    @endif
                                </td>
                                <td class="map-sample">{{ $sampleValue }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Sample preview --}}
        @php($mappedFields = array_filter($fields, fn ($f) => ($mapping[$f->key] ?? '') !== ''))
        @if (!empty($mappedFields) && !empty($sampleRows))
            <section class="table-shell flux-panel form-panel">
                <div class="form-panel-header">
                    <div>
                        <strong>{{ __('sku_import.map_sample_heading') }}</strong>
                    </div>
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

        <div class="form-actions">
            <flux:button wire:click="backToUpload">{{ __('sku_import.btn_back_to_upload') }}</flux:button>
            <flux:button variant="primary" wire:click="advanceToPreview" wire:loading.attr="disabled" wire:target="advanceToPreview">
                <span wire:loading.remove wire:target="advanceToPreview">{{ __('sku_import.btn_advance_to_preview') }}</span>
                <span wire:loading wire:target="advanceToPreview">...</span>
            </flux:button>
        </div>
    @endif

    {{-- Step 3: Preview + options --}}
    @if ($step === 'preview')
        {{-- Summary --}}
        <section class="table-shell flux-panel form-panel">
            <div class="import-summary">
                <span class="badge badge-success">{{ __('sku_import.preview_valid_count', ['count' => $validRowCount]) }}</span>
                @if ($existsRowCount > 0)
                    <span class="badge badge-warning">{{ __('sku_import.preview_exists_count', ['count' => $existsRowCount]) }}</span>
                @endif
                @if ($errorRowCount > 0)
                    <span class="badge badge-danger">{{ __('sku_import.preview_error_count', ['count' => $errorRowCount]) }}</span>
                @endif
                <span class="badge import-badge-total">{{ __('sku_import.preview_total_count', ['count' => $totalDataRows]) }}</span>
            </div>

            @if ($validRowCount === 0 && $existsRowCount === 0)
                <p class="form-error">{{ __('sku_import.preview_no_valid_rows') }}</p>
            @endif
        </section>

        {{-- Insert / upsert mode -- only shown when existing SKUs are detected --}}
        @if ($existsRowCount > 0)
            <section class="table-shell flux-panel form-panel">
                <div class="form-panel-header">
                    <div>
                        <strong>{{ __('sku_import.preview_exists_count', ['count' => $existsRowCount]) }}</strong>
                        <span>{{ __('sku_import.error_mode_required') }}</span>
                    </div>
                </div>
                <div class="segmented-row stacked">
                    <label>
                        <input type="radio" wire:model.live="allowUpsert" value="0">
                        <span>{{ __('sku_import.option_insert_only') }}</span>
                    </label>
                    <label>
                        <input type="radio" wire:model.live="allowUpsert" value="1">
                        <span>{{ __('sku_import.option_upsert') }}</span>
                    </label>
                </div>
            </section>
        @endif

        {{-- Save template --}}
        <section class="table-shell flux-panel form-panel">
            <div class="checkbox-stack">
                <label><input type="checkbox" wire:model.live="doSaveTemplate"> {{ __('sku_import.option_save_template') }}</label>
            </div>
            @if ($doSaveTemplate)
                <flux:input wire:model="saveTemplateName" :label="__('sku_import.option_template_name')" :placeholder="__('sku_import.option_template_name')" />
            @endif
        </section>

        {{-- Row preview --}}
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sku_import.preview_heading') }}</strong>
                    <span>{{ __('sku_import.preview_hint') }}</span>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('sku_import.col_row') }}</th>
                            <th>{{ __('sku_import.col_sku') }}</th>
                            <th>{{ __('sku_import.col_name') }}</th>
                            <th>{{ __('sku_import.col_status') }}</th>
                            <th>{{ __('sku_import.col_errors') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($previewRows as $previewRow)
                            <tr>
                                <td>{{ $previewRow['row'] }}</td>
                                <td><strong>{{ $previewRow['sku'] }}</strong></td>
                                <td>{{ $previewRow['name'] }}</td>
                                <td>
                                    @if ($previewRow['status'] === 'valid')
                                        <span class="badge badge-success">{{ __('sku_import.status_valid') }}</span>
                                    @elseif ($previewRow['status'] === 'exists')
                                        <span class="badge badge-warning">{{ __('sku_import.status_exists') }}</span>
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
                :disabled="$validRowCount === 0 && $existsRowCount === 0"
            >
                <span wire:loading.remove wire:target="confirmImport">{{ __('sku_import.btn_confirm_import') }}</span>
                <span wire:loading wire:target="confirmImport">...</span>
            </flux:button>
        </div>
    @endif

    {{-- Step 4: Result --}}
    @if ($step === 'result')
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sku_import.result_heading') }}</strong>
                </div>
            </div>

            <div class="import-stats">
                <div class="import-stat is-created">
                    <strong>{{ $resultCreated }}</strong>
                    <span>{{ __('sku_import.result_created', ['count' => $resultCreated]) }}</span>
                </div>
                <div class="import-stat is-updated">
                    <strong>{{ $resultUpdated }}</strong>
                    <span>{{ __('sku_import.result_updated', ['count' => $resultUpdated]) }}</span>
                </div>
                <div class="import-stat is-skipped">
                    <strong>{{ $resultSkipped }}</strong>
                    <span>{{ __('sku_import.result_skipped', ['count' => $resultSkipped]) }}</span>
                </div>
                @if ($resultFailed > 0)
                    <div class="import-stat is-failed">
                        <strong>{{ $resultFailed }}</strong>
                        <span>{{ __('sku_import.result_failed', ['count' => $resultFailed]) }}</span>
                    </div>
                @endif
            </div>

            <div class="form-actions">
                @if ($resultFailed > 0)
                    <flux:button variant="primary" wire:click="downloadErrors">{{ __('sku_import.btn_download_errors') }}</flux:button>
                @else
                    <span></span>
                @endif
                <div class="template-actions">
                    <flux:button href="{{ route('skus.index') }}" variant="primary" wire:navigate>{{ __('sku_import.btn_view_skus') }}</flux:button>
                    <flux:button variant="primary" wire:click="startOver">{{ __('sku_import.btn_import_more') }}</flux:button>
                </div>
            </div>
        </section>
    @endif
</div>
