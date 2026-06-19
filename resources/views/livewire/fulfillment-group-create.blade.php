<div class="fulfillment-group-create-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    @if (session('error'))
        <div class="active-filter-row">
            <flux:badge color="red">{{ session('error') }}</flux:badge>
        </div>
    @endif

    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('fulfillment_groups.create_page_title') }}</strong>
                    <span>{{ __('fulfillment_groups.create_page_subtitle') }}</span>
                </div>
                <flux:button href="{{ route('fulfillment-groups.index') }}" variant="outline" wire:navigate>
                    {{ __('fulfillment_groups.btn_back') }}
                </flux:button>
            </div>

            <div class="form-grid three">
                @if ($showTenantSelect)
                    <flux:select wire:model.live="tenantId" required :label="__('fulfillment_groups.field_tenant')">
                        <flux:select.option value="">{{ __('fulfillment_groups.select_tenant') }}</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:select wire:model.live="warehouseId" required :label="__('fulfillment_groups.field_warehouse')">
                    <flux:select.option value="">{{ __('fulfillment_groups.select_warehouse') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="shipKey" required :label="__('fulfillment_groups.field_ship_key')">
                    <flux:select.option value="">{{ __('fulfillment_groups.select_ship_key') }}</flux:select.option>
                    @foreach ($shipKeyOptions as $option)
                        <flux:select.option value="{{ $option->ship_together_key }}">
                            {{ $option->recipient_name ?: '-' }} / {{ $option->recipient_city ?: '-' }} / {{ trans_choice('fulfillment_groups.order_count', $option->order_count, ['count' => $option->order_count]) }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @foreach (['tenant_id', 'warehouse_id', 'ship_key'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('fulfillment_groups.section_orders') }}</strong>
                    <span>{{ __('fulfillment_groups.section_orders_hint') }}</span>
                </div>
            </div>

            @error('selected_order_ids') <p class="form-error">{{ $message }}</p> @enderror
            @error('selectedOrderIds') <p class="form-error">{{ $message }}</p> @enderror

            <flux:table class="movement-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('sales_orders.col_platform_order_id') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_recipient') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('sales_orders.col_qty') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_created_at') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($eligibleOrders as $order)
                        <flux:table.row :key="$order->id">
                            <flux:table.cell>
                                <label class="checkbox-line">
                                    <input type="checkbox" wire:model="selectedOrderIds" value="{{ $order->id }}">
                                    <span>
                                        <strong>{{ $order->platform_order_id ?: '#'.$order->id }}</strong>
                                        <span class="subtle">#{{ $order->id }}</span>
                                    </span>
                                </label>
                            </flux:table.cell>
                            <flux:table.cell>
                                <strong>{{ $order->recipient_name ?: '-' }}</strong>
                                <span class="subtle">{{ $order->recipient_city ?: $order->recipient_postal_code ?: '-' }}</span>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($order->lines_count) }}</flux:table.cell>
                            <flux:table.cell>{{ $order->created_at->format('Y-m-d') }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4">
                                <div class="empty-state">{{ __('fulfillment_groups.no_ready_orders') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('fulfillment-groups.index') }}" variant="outline" wire:navigate>
                {{ __('fulfillment_groups.btn_cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary">
                {{ __('fulfillment_groups.btn_create') }}
            </flux:button>
        </div>
    </form>
</div>
