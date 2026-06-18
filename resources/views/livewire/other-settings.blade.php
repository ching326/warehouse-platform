<div class="other-settings-page">
    @if (session('saved'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ __('setup.saved') }}</flux:badge>
        </div>
    @endif

    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('setup.product_types_title') }}</strong>
                    <span>{{ __('setup.product_types_hint') }}</span>
                </div>
            </div>

            <div class="type-grid">
                <div class="type-grid-header">
                    <span>{{ __('setup.col_sort') }}</span>
                    <span>{{ __('setup.col_slug') }}</span>
                    <span>EN</span>
                    <span>繁中</span>
                    <span>簡中</span>
                    <span>日語</span>
                    <span></span>
                </div>

                @foreach ($types as $index => $type)
                    <div class="type-grid-row">
                        <flux:input
                            type="number"
                            wire:model="types.{{ $index }}.sort_order"
                            min="0"
                            step="1"
                        />
                        @if ($type['id'])
                            <div class="type-slug-cell">
                                <code>{{ $type['slug'] }}</code>
                                <input type="hidden" wire:model="types.{{ $index }}.slug" />
                                <input type="hidden" wire:model="types.{{ $index }}.name" />
                            </div>
                        @else
                            <flux:input
                                wire:model="types.{{ $index }}.slug"
                                placeholder="slug"
                            />
                        @endif
                        <flux:input
                            wire:model="types.{{ $index }}.translations.en"
                            placeholder="English"
                        />
                        <flux:input
                            wire:model="types.{{ $index }}.translations.zh_TW"
                            placeholder="繁體中文"
                        />
                        <flux:input
                            wire:model="types.{{ $index }}.translations.zh_CN"
                            placeholder="简体中文"
                        />
                        <flux:input
                            wire:model="types.{{ $index }}.translations.ja"
                            placeholder="日本語"
                        />
                        <button
                            type="button"
                            class="remove-line-btn"
                            wire:click="removeType({{ $index }})"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                        </button>
                    </div>

                    @error("types.{$index}.slug") <p class="form-error type-grid-error">{{ $message }}</p> @enderror
                    @error("types.{$index}.name") <p class="form-error type-grid-error">{{ $message }}</p> @enderror
                @endforeach
            </div>

            <div style="margin-top: 12px;">
                <flux:button type="button" variant="outline" wire:click="addType">{{ __('setup.btn_add_type') }}</flux:button>
            </div>
        </section>

        <div class="form-actions">
            <flux:button type="submit" variant="primary">{{ __('setup.btn_save') }}</flux:button>
        </div>
    </form>
</div>
