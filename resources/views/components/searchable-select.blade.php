@props([
    'label',
    'model',
    'searchModel',
    'options' => [],
    'selectedLabel' => '',
    'placeholder' => '',
    'emptyLabel' => 'No results',
    'required' => false,
])

@php
    $optionItems = collect($options)
        ->map(fn ($option) => [
            'value' => (string) $option['value'],
            'label' => (string) $option['label'],
            'meta' => (string) ($option['meta'] ?? ''),
        ])
        ->values();
@endphp

<div
    {{ $attributes->class('searchable-select') }}
    x-data="{
        open: false,
        query: @js($selectedLabel),
        options: {{ Illuminate\Support\Js::from($optionItems) }},
        choose(option) {
            this.query = option.label;
            this.open = false;
            $wire.set(@js($model), option.value);
            $wire.set(@js($searchModel), option.label);
        },
        markTyped() {
            $wire.set(@js($model), '');
        },
    }"
    x-on:click.outside="open = false"
>
    <label>
        <span>{{ $label }}</span>
        <input
            type="text"
            x-model="query"
            x-on:focus="open = true"
            x-on:input="open = true; markTyped()"
            wire:model.live.debounce.250ms="{{ $searchModel }}"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            @required($required)
        >
    </label>

    <div class="searchable-select-menu" x-cloak x-show="open">
        <template x-if="options.length === 0">
            <div class="searchable-select-empty">{{ $emptyLabel }}</div>
        </template>

        <template x-for="option in options" :key="option.value">
            <button type="button" class="searchable-select-option" x-on:mousedown.prevent="choose(option)">
                <strong x-text="option.label"></strong>
                <span x-show="option.meta" x-text="option.meta"></span>
            </button>
        </template>
    </div>
</div>
