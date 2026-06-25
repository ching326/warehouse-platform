<div class="sku-import-page">
    {{-- Step indicator --}}
    <div class="import-steps">
        @foreach (['upload' => __('sku_import.step_upload'), 'map' => __('sku_import.step_map'), 'preview' => __('sku_import.step_preview'), 'result' => __('sku_import.step_result')] as $stepKey => $stepLabel)
            @php($stepKeys = ['upload', 'map', 'preview', 'result'])
            @php($currentIdx = array_search($step, $stepKeys))
            @php($thisIdx = array_search($stepKey, $stepKeys))
            <span @class(['import-step', 'is-active' => $step === $stepKey, 'is-done' => $thisIdx < $currentIdx])>
                {{ $stepLabel }}
            </span>
        @endforeach
    </div>

    {{-- Step 1: Upload --}}
    @if ($step === 'upload')
        <section class="flux-panel import-section">
            <form wire:submit="readFile">
                @if ($showTenantSelect)
                    <flux:select wire:model.live="tenantId" label="{{ __('skus.field_tenant') }}" required>
                        <option value="">{{ __('skus.select_tenant') }}</option>
                        @foreach ($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </flux:select>
                    @error('tenantId') <p class="field-error">{{ $message }}</p> @enderror
                @endif

                <flux:select wire:model.live="shopId" label="{{ __('skus.field_shop') }}">
                    <option value="">{{ __('skus.no_shop') }}</option>
                    @foreach ($shops as $shop)
                        <option value="{{ $shop->id }}">{{ $shop->name }}</option>
                    @endforeach
                </flux:select>
                @error('shopId') <p class="field-error">{{ $message }}</p> @enderror

                <div class="field-group">
                    <label class="field-label">{{ __('skus.field_sku') }} CSV / Excel</label>
                    <p class="field-hint">{{ __('sku_import.upload_hint') }}</p>
                    <input type="file" wire:model="file" accept=".csv,.txt,.xlsx,.xls" class="file-input">
                    @error('file') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="form-actions">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="readFile">{{ __('sku_import.btn_upload') }}</span>
                        <span wire:loading wire:target="readFile">...</span>
                    </flux:button>
                </div>
            </form>
        </section>
    @endif

    {{-- Step 2: Map fields --}}
    @if ($step === 'map')
        @error('mapping') <p class="field-error">{{ $message }}</p> @enderror

        {{-- Saved templates --}}
        <section class="flux-panel import-section">
            <h3 class="section-title">{{ __('sku_import.map_templates_heading') }}</h3>
            @if ($savedTemplates->isEmpty())
                <p class="muted-hint">{{ __('sku_import.map_no_templates') }}</p>
            @else
                <div class="template-list">
                    @foreach ($savedTemplates as $template)
                        <div class="template-row">
                            <span>{{ $template->name }}</span>
                            <flux:button size="sm" wire:click="loadTemplate({{ $template->id }})">{{ __('sku_import.map_btn_load') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="deleteTemplate({{ $template->id }})" wire:confirm="Delete this template?">{{ __('sku_import.map_btn_delete') }}</flux:button>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- SKU fields --}}
        <section class="flux-panel import-section">
            <h3 class="section-title">{{ __('sku_import.map_group_sku') }}</h3>
            <p class="field-hint">{{ __('sku_import.map_hint') }}</p>
            <table class="mapping-table">
                <thead>
                    <tr>
                        <th>{{ __('sku_import.map_col_field') }}</th>
                        <th>{{ __('sku_import.map_col_file_column') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fields as $field)
                        @if ($field->target === 'sku')
                            <tr>
                                <td>
                                    {{ __($field->labelKey) }}
                                    @if ($field->required)
                                        <span class="badge-required">{{ __('sku_import.map_required_badge') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <select wire:model.live="mapping.{{ $field->key }}" class="map-select">
                                        <option value="">{{ __('sku_import.map_ignore') }}</option>
                                        @foreach ($fileHeaders as $header)
                                            <option value="{{ $header }}">{{ $header }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </section>

        {{-- Stock item fields --}}
        <section class="flux-panel import-section">
            <h3 class="section-title">{{ __('sku_import.map_group_stock_item') }}</h3>
            <table class="mapping-table">
                <thead>
                    <tr>
                        <th>{{ __('sku_import.map_col_field') }}</th>
                        <th>{{ __('sku_import.map_col_file_column') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fields as $field)
                        @if ($field->target === 'stock_item')
                            <tr>
                                <td>{{ __($field->labelKey) }}</td>
                                <td>
                                    <select wire:model.live="mapping.{{ $field->key }}" class="map-select">
                                        <option value="">{{ __('sku_import.map_ignore') }}</option>
                                        @foreach ($fileHeaders as $header)
                                            <option value="{{ $header }}">{{ $header }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </section>

        {{-- Sample preview --}}
        @php($mappedFields = array_filter($fields, fn ($f) => ($mapping[$f->key] ?? '') !== ''))
        @if (!empty($mappedFields) && !empty($sampleRows))
            <section class="flux-panel import-section">
                <h3 class="section-title">{{ __('sku_import.map_sample_heading') }}</h3>
                <div class="table-responsive">
                    <table class="preview-table">
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
            <flux:button variant="primary" wire:click="advanceToPreview" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="advanceToPreview">{{ __('sku_import.btn_advance_to_preview') }}</span>
                <span wire:loading wire:target="advanceToPreview">...</span>
            </flux:button>
        </div>
    @endif

    {{-- Step 3: Preview + options --}}
    @if ($step === 'preview')
        {{-- Summary badges --}}
        <section class="flux-panel import-section">
            <div class="preview-summary">
                <span class="summary-badge badge-valid">{{ __('sku_import.preview_valid_count', ['count' => $validRowCount]) }}</span>
                <span class="summary-badge badge-exists">{{ __('sku_import.preview_exists_count', ['count' => $existsRowCount]) }}</span>
                @if ($errorRowCount > 0)
                    <span class="summary-badge badge-error">{{ __('sku_import.preview_error_count', ['count' => $errorRowCount]) }}</span>
                @endif
                <span class="summary-badge">{{ __('sku_import.preview_total_count', ['count' => $totalDataRows]) }}</span>
            </div>

            @if ($validRowCount === 0 && $existsRowCount === 0)
                <p class="field-error">{{ __('sku_import.preview_no_valid_rows') }}</p>
            @endif
        </section>

        {{-- Options --}}
        <section class="flux-panel import-section">
            <div class="option-row">
                <label class="option-label">
                    <input type="radio" wire:model.live="allowUpsert" value="0">
                    {{ __('sku_import.option_insert_only') }}
                </label>
                <label class="option-label">
                    <input type="radio" wire:model.live="allowUpsert" value="1">
                    {{ __('sku_import.option_upsert') }}
                </label>
            </div>

            <div class="option-row">
                <label class="option-label">
                    <input type="checkbox" wire:model.live="doSaveTemplate">
                    {{ __('sku_import.option_save_template') }}
                </label>
                @if ($doSaveTemplate)
                    <flux:input wire:model="saveTemplateName" placeholder="{{ __('sku_import.option_template_name') }}" />
                    @error('saveTemplateName') <p class="field-error">{{ $message }}</p> @enderror
                @endif
            </div>
        </section>

        {{-- Row preview table --}}
        <section class="flux-panel import-section">
            <h3 class="section-title">{{ __('sku_import.preview_heading') }}</h3>
            <p class="field-hint">{{ __('sku_import.preview_hint') }}</p>
            <table class="preview-table">
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
                        <tr @class(['row-valid' => $previewRow['status'] === 'valid', 'row-exists' => $previewRow['status'] === 'exists', 'row-error' => $previewRow['status'] === 'error'])>
                            <td>{{ $previewRow['row'] }}</td>
                            <td>{{ $previewRow['sku'] }}</td>
                            <td>{{ $previewRow['name'] }}</td>
                            <td>
                                @if ($previewRow['status'] === 'valid')
                                    <span class="badge-valid">{{ __('sku_import.status_valid') }}</span>
                                @elseif ($previewRow['status'] === 'exists')
                                    <span class="badge-exists">{{ __('sku_import.status_exists') }}</span>
                                @else
                                    <span class="badge-error">{{ __('sku_import.status_error') }}</span>
                                @endif
                            </td>
                            <td>{{ implode(' | ', $previewRow['errors']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <div class="form-actions">
            <flux:button wire:click="backToMap">{{ __('sku_import.btn_back_to_map') }}</flux:button>
            <flux:button
                variant="primary"
                wire:click="confirmImport"
                wire:loading.attr="disabled"
                :disabled="$validRowCount === 0 && $existsRowCount === 0"
            >
                <span wire:loading.remove wire:target="confirmImport">{{ __('sku_import.btn_confirm_import') }}</span>
                <span wire:loading wire:target="confirmImport">...</span>
            </flux:button>
        </div>
    @endif

    {{-- Step 4: Result --}}
    @if ($step === 'result')
        <section class="flux-panel import-section">
            <h3 class="section-title">{{ __('sku_import.result_heading') }}</h3>
            <div class="result-counts">
                <div class="result-item">
                    <strong>{{ $resultCreated }}</strong>
                    <span>{{ __('sku_import.result_created', ['count' => $resultCreated]) }}</span>
                </div>
                <div class="result-item">
                    <strong>{{ $resultUpdated }}</strong>
                    <span>{{ __('sku_import.result_updated', ['count' => $resultUpdated]) }}</span>
                </div>
                <div class="result-item">
                    <strong>{{ $resultSkipped }}</strong>
                    <span>{{ __('sku_import.result_skipped', ['count' => $resultSkipped]) }}</span>
                </div>
                @if ($resultFailed > 0)
                    <div class="result-item result-failed">
                        <strong>{{ $resultFailed }}</strong>
                        <span>{{ __('sku_import.result_failed', ['count' => $resultFailed]) }}</span>
                    </div>
                @endif
            </div>

            @if ($resultFailed > 0)
                <flux:button wire:click="downloadErrors">{{ __('sku_import.btn_download_errors') }}</flux:button>
            @endif

            <div class="form-actions">
                <flux:button href="{{ route('skus.index') }}" variant="subtle">{{ __('sku_import.btn_view_skus') }}</flux:button>
                <flux:button variant="primary" wire:click="startOver">{{ __('sku_import.btn_import_more') }}</flux:button>
            </div>
        </section>
    @endif
</div>
