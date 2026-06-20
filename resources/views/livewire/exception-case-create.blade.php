<div class="exception-case-create-page">
    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('exception_cases.section_case') }}</strong>
                <span>{{ __('exception_cases.no_inventory_hint') }}</span>
            </div>
        </div>

        <div class="form-grid three">
            @if ($showTenantSelect)
                <flux:select wire:model.live="tenantId" required :label="__('exception_cases.field_tenant')">
                    <flux:select.option value="">{{ __('common.select_tenant') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="caseType" required :label="__('exception_cases.field_case_type')">
                @foreach ($types as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="status" required :label="__('exception_cases.field_status')">
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="salesOrderId" :label="__('exception_cases.field_sales_order')">
                <flux:select.option value="">{{ __('exception_cases.select_sales_order') }}</flux:select.option>
                @foreach ($salesOrders as $order)
                    <flux:select.option value="{{ $order->id }}">{{ $order->platform_order_id ?: '#'.$order->id }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="outboundOrderId" :label="__('exception_cases.field_outbound_order')">
                <flux:select.option value="">{{ __('exception_cases.select_outbound_order') }}</flux:select.option>
                @foreach ($outboundOrders as $order)
                    <flux:select.option value="{{ $order->id }}">{{ $order->ref ?: '#'.$order->id }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="reportedAt" type="datetime-local" :label="__('exception_cases.field_reported_at')" />
            <flux:input wire:model="reportedBy" :label="__('exception_cases.field_reported_by')" />
        </div>

        <label class="form-grid-wide">
            <span>{{ __('exception_cases.field_note') }}</span>
            <textarea wire:model="note" rows="3"></textarea>
        </label>

        @foreach (['tenantId', 'salesOrderId', 'case_type', 'status', 'lines', 'manualLines'] as $field)
            @error($field) <p class="form-error">{{ $message }}</p> @enderror
        @endforeach
    </section>

    @if ($salesOrderLines)
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('exception_cases.section_sales_order_lines') }}</strong>
                    <span>{{ __('exception_cases.sales_order_lines_hint') }}</span>
                </div>
            </div>

            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('exception_cases.col_select') }}</flux:table.column>
                    <flux:table.column>{{ __('exception_cases.col_sku_stock') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('exception_cases.col_source_qty') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('exception_cases.field_qty') }}</flux:table.column>
                    <flux:table.column>{{ __('exception_cases.field_condition') }}</flux:table.column>
                    <flux:table.column>{{ __('exception_cases.field_action') }}</flux:table.column>
                    <flux:table.column>{{ __('exception_cases.field_line_note') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($salesOrderLines as $index => $line)
                        <flux:table.row :key="$line['sales_order_line_id']">
                            <flux:table.cell><input type="checkbox" wire:model.live="salesOrderLines.{{ $index }}.selected"></flux:table.cell>
                            <flux:table.cell>
                                <strong>{{ $line['label'] }}</strong>
                                <span class="subtle">{{ $line['stock_item'] ?: __('common.sku_types.virtual_bundle') }}</span>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($line['max_qty']) }}</flux:table.cell>
                            <flux:table.cell align="end"><input type="number" min="1" max="{{ $line['max_qty'] }}" wire:model="salesOrderLines.{{ $index }}.qty"></flux:table.cell>
                            <flux:table.cell>
                                <select wire:model="salesOrderLines.{{ $index }}.condition">
                                    @foreach ($conditions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                            <flux:table.cell>
                                <select wire:model="salesOrderLines.{{ $index }}.action">
                                    @foreach ($actions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                            <flux:table.cell><input type="text" wire:model="salesOrderLines.{{ $index }}.note"></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('exception_cases.section_manual_lines') }}</strong>
                <span>{{ __('exception_cases.manual_lines_hint') }}</span>
            </div>
            <flux:button type="button" variant="outline" wire:click="addManualLine">{{ __('exception_cases.btn_add_line') }}</flux:button>
        </div>

        @foreach ($manualLines as $index => $line)
            <div class="line-row">
                <flux:select wire:model="manualLines.{{ $index }}.sku_id" :label="__('exception_cases.field_sku')">
                    <flux:select.option value="">{{ __('exception_cases.select_sku') }}</flux:select.option>
                    @foreach ($skuOptions as $sku)
                        <flux:select.option value="{{ $sku->id }}">{{ $sku->sku }} - {{ $sku->name }} / {{ $sku->stockItem?->code ?? __('common.sku_types.virtual_bundle') }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="manualLines.{{ $index }}.qty" type="number" min="1" step="1" :label="__('exception_cases.field_qty')" />
                <flux:select wire:model="manualLines.{{ $index }}.condition" :label="__('exception_cases.field_condition')">
                    @foreach ($conditions as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <button type="button" class="remove-line-btn {{ count($manualLines) <= 1 ? 'invisible' : '' }}" wire:click="removeManualLine({{ $index }})">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                </button>
            </div>
            <div class="form-grid two-one">
                <flux:select wire:model="manualLines.{{ $index }}.action" :label="__('exception_cases.field_action')">
                    @foreach ($actions as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="manualLines.{{ $index }}.note" :label="__('exception_cases.field_line_note')" />
            </div>
        @endforeach
    </section>

    <div class="form-actions">
        <flux:button href="{{ route('exception-cases.index') }}" variant="outline" wire:navigate>{{ __('common.cancel') }}</flux:button>
        <flux:button type="button" variant="primary" wire:click="save">{{ __('exception_cases.btn_save') }}</flux:button>
    </div>
</div>
