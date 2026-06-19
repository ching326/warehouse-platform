<div class="shipping-method-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('shipping.create_page_title') }}</strong>
                    <span>{{ __('shipping.index_page_subtitle') }}</span>
                </div>
                <flux:button href="{{ route('setup.shipping-methods.index') }}" variant="outline">{{ __('shipping.btn_back_methods') }}</flux:button>
            </div>

            @include('livewire.shipping-method-form')
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('setup.shipping-methods.index') }}" variant="outline">{{ __('sales_orders.btn_cancel_edit') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('shipping.btn_create_method') }}</flux:button>
        </div>
    </form>
</div>
