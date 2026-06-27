@php($aliasCanManage = $alias->source !== \App\Models\BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE)

<article class="alias-list-item" wire:key="{{ $keyPrefix }}-alias-{{ $alias->id }}">
    @if ($editingAliasId === $alias->id)
        <div class="alias-edit-form">
            <input class="alias-edit-input" type="text" wire:model="aliasEdit.barcode" aria-label="{{ __('skus.alias_barcode') }}">
            <select class="alias-type-select" wire:model="aliasEdit.barcode_type" aria-label="{{ __('skus.alias_barcode_type') }}">
                @foreach ($this->barcodeAliasTypeOptions() as $type => $label)
                    <option value="{{ $type }}">{{ $label }}</option>
                @endforeach
            </select>
            <input class="alias-edit-input" type="text" wire:model="aliasEdit.label" aria-label="{{ __('skus.alias_label') }}" placeholder="{{ __('skus.alias_label') }}">

            @foreach (['aliasEdit.barcode', 'aliasEdit.barcode_type', 'aliasEdit.label'] as $field)
                @error($field)
                    <span class="field-error">{{ $message }}</span>
                @enderror
            @endforeach
        </div>
        <div class="alias-row-actions">
            <flux:button type="button" size="xs" variant="primary" wire:click="saveBarcodeAlias({{ $alias->id }})">
                {{ __('skus.alias_save') }}
            </flux:button>
            <flux:button type="button" size="xs" variant="outline" wire:click="cancelEditBarcodeAlias">
                {{ __('skus.alias_cancel') }}
            </flux:button>
        </div>
    @else
        <div>
            <div class="alias-heading">
                <strong>{{ $alias->barcode }}</strong>
                <flux:badge color="{{ $alias->is_active ? 'green' : 'zinc' }}">
                    {{ $alias->is_active ? __('skus.alias_active') : __('skus.alias_inactive') }}
                </flux:badge>
                <span class="alias-type-display">{{ $this->barcodeAliasTypeOptions()[$alias->barcode_type] ?? $alias->barcode_type }}</span>
                @if ($alias->label)
                    <span class="alias-note">{{ $alias->label }}</span>
                @endif
            </div>
            @if ($alias->source === \App\Models\BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE)
                <small>{{ __('skus.alias_source_fnsku_field') }}</small>
            @endif
        </div>
        <div class="alias-row-actions">
            <flux:button type="button" size="xs" variant="outline" wire:click="editBarcodeAlias({{ $alias->id }})" :disabled="! $aliasCanManage">
                {{ __('skus.alias_edit') }}
            </flux:button>
            @if ($alias->is_active)
                <flux:button class="alias-deactivate-button" type="button" size="xs" variant="danger" wire:click="deactivateBarcodeAlias({{ $alias->id }})" wire:confirm="{{ __('skus.alias_confirm_deactivate') }}" :disabled="! $aliasCanManage">
                    {{ __('skus.alias_deactivate') }}
                </flux:button>
            @else
                <flux:button type="button" size="xs" variant="primary" wire:click="reactivateBarcodeAlias({{ $alias->id }})" wire:confirm="{{ __('skus.alias_confirm_reactivate') }}" :disabled="! $aliasCanManage">
                    {{ __('skus.alias_reactivate') }}
                </flux:button>
            @endif
        </div>
    @endif
</article>
