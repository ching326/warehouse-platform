@props([
    'label',
    'model',
    'searchModel',
    'options' => [],
    'selectedLabel' => '',
    'placeholder' => '',
    'emptyLabel' => 'No results',
    'required' => false,
    'disabled' => false,
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
        choose(option) {
            this.query = option.label;
            this.open = false;
            $wire.set(@js($model), option.value);
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
            x-on:input="open = true"
            wire:model.live.debounce.150ms="{{ $searchModel }}"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            @required($required)
            @disabled($disabled)
        >
    </label>

    <div class="searchable-select-menu" x-cloak x-show="open && ! @js($disabled)">
        @forelse ($optionItems as $option)
            <button
                type="button"
                class="searchable-select-option"
                x-on:mousedown.prevent="choose(@js($option))"
            >
                <strong>{{ $option['label'] }}</strong>
                @if ($option['meta'] !== '')
                    <span>{{ $option['meta'] }}</span>
                @endif
            </button>
        @empty
            <div class="searchable-select-empty">{{ $emptyLabel }}</div>
        @endforelse
    </div>
</div>
