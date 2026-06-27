<div class="other-settings-page">
    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('common.nav_warehouses') }}</strong>
                <span>{{ __('setup.warehouses_page_subtitle') }}</span>
            </div>
            <flux:button href="{{ route('setup.warehouses.index') }}" variant="primary">
                {{ __('common.view') }}
            </flux:button>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('setup.product_types_title') }}</strong>
                <span>{{ __('setup.product_types_hint') }}</span>
            </div>
            <flux:button href="{{ route('setup.product-types.index') }}" variant="primary">
                {{ __('common.view') }}
            </flux:button>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('common.nav_fba_warehouses') }}</strong>
                <span>{{ __('setup.fba_warehouses_page_subtitle') }}</span>
            </div>
            <flux:button href="{{ route('setup.fba-warehouses.index') }}" variant="primary">
                {{ __('common.view') }}
            </flux:button>
        </div>
    </section>
</div>
