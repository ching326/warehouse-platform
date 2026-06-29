<div class="return-order-index-page">
    <x-flash-toast />

    <x-page-panel-header
        :title="__('return_orders.page_title')"
        :subtitle="__('return_orders.page_subtitle')"
        :show-nav="false"
    />

    <section class="table-shell flux-panel">
<div class="movement-toolbar">
        @if ($showTenantSelect)<flux:select wire:model.live="tenantId" :label="__('return_orders.field_tenant')"><flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>@foreach($tenants as $tenant)<flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>@endforeach</flux:select>@endif
        <flux:select wire:model.live="warehouseId" :label="__('return_orders.field_warehouse')"><flux:select.option value="">{{ __('return_orders.all_warehouses') }}</flux:select.option>@foreach($warehouses as $warehouse)<flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>@endforeach</flux:select>
        <flux:select wire:model.live="statusFilter" :label="__('return_orders.field_status')"><flux:select.option value="">{{ __('return_orders.all_statuses') }}</flux:select.option>@foreach($statuses as $value=>$label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach</flux:select>
        <flux:select wire:model.live="typeFilter" :label="__('return_orders.field_return_type')"><flux:select.option value="">{{ __('return_orders.all_types') }}</flux:select.option>@foreach($types as $value=>$label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach</flux:select>
        <flux:button href="{{ route('return-orders.create') }}" variant="primary" wire:navigate>{{ __('return_orders.btn_create') }}</flux:button>
        <flux:input wire:model.live.debounce.300ms="search" :label="__('common.search')" :placeholder="__('return_orders.search_placeholder')" />
    </div>
    <flux:table :paginate="$orders" class="data-table">
        <flux:table.columns><flux:table.column>{{ __('return_orders.col_return_no') }}</flux:table.column><flux:table.column>{{ __('return_orders.col_type_reason') }}</flux:table.column><flux:table.column>{{ __('return_orders.col_tracking') }}</flux:table.column><flux:table.column>{{ __('return_orders.col_customer_order') }}</flux:table.column><flux:table.column>{{ __('return_orders.col_status') }}</flux:table.column><flux:table.column>{{ __('return_orders.col_costs') }}</flux:table.column></flux:table.columns>
        <flux:table.rows>@forelse($orders as $order)<flux:table.row :key="$order->id"><flux:table.cell><a class="return-order-number-text" href="{{ route('return-orders.show',$order) }}" wire:navigate>{{ $order->return_no }}</a></flux:table.cell><flux:table.cell>{{ $order->typeLabel() }}<span class="subtle">{{ $order->reasonLabel() }}</span></flux:table.cell><flux:table.cell><strong>{{ $order->tracking_no ?: '-' }}</strong><span class="subtle">{{ $order->shipping_method ?: '' }}</span></flux:table.cell><flux:table.cell>{{ $order->customer_name ?: '-' }}<span class="subtle">{{ $order->original_order_no ?: $order->external_return_id }}</span></flux:table.cell><flux:table.cell><flux:badge color="{{ $order->statusColor() }}">{{ $order->statusLabel() }}</flux:badge></flux:table.cell><flux:table.cell>JPY {{ number_format($order->costs->sum('amount'),0) }}</flux:table.cell></flux:table.row>@empty<flux:table.row><flux:table.cell colspan="6"><div class="empty-state">{{ __('return_orders.empty_state') }}</div></flux:table.cell></flux:table.row>@endforelse</flux:table.rows>
    </flux:table>
    </section>

    <style>
        .return-order-number-text {
            color: #2563eb;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .return-order-number-text:hover {
            color: #1d4ed8;
            text-decoration: none;
        }
    </style>
</div>
