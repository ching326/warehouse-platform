@php
    $localeLabels = ['ja' => '日本語', 'zh_TW' => '繁體中文', 'zh_CN' => '简体中文'];
    $currentLocale = app()->getLocale();
    $isRequired = $required ?? false;
    $baseLocale = $baseLocale ?? null;
    $displayLabel = ($baseLocale && isset($localeLabels[$baseLocale]))
        ? $label.' ('.$localeLabels[$baseLocale].')'
        : $label;
    $displayLocaleModels = $localeModels;
    if ($baseLocale) {
        unset($displayLocaleModels[$baseLocale]);
    }
@endphp

<div class="localized-field" x-data="{ open: @js($openInitially ?? false) }">
    <div class="localized-field-base">
        <flux:input wire:model="{{ $baseModel }}" :label="$displayLabel" :required="$isRequired" />
        <button
            type="button"
            class="localized-field-toggle"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open"
        >
            <flux:icon.globe-alt class="localized-field-toggle-icon" />
            <span>{{ __('skus.translations') }}</span>
        </button>
    </div>

    <div class="localized-field-panel" x-show="open" x-cloak>
        @foreach ($displayLocaleModels as $locale => $model)
            <div @class(['localized-field-locale', 'is-current' => $locale === $currentLocale])>
                <flux:input
                    wire:model="{{ $model }}"
                    :label="$localeLabels[$locale] ?? $locale"
                    :placeholder="__('skus.translation_placeholder')"
                />
            </div>
        @endforeach
    </div>
</div>
