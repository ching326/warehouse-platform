@props([
    'href',
    'value',
    'copyLabel' => __('common.copy'),
    'copiedLabel' => __('common.copied'),
])

<span
    class="record-ref-line"
    x-data="{
        copied: false,
        async copy(value) {
            if (! value) {
                return;
            }

            try {
                await navigator.clipboard.writeText(value);
            } catch (error) {
                const input = document.createElement('textarea');
                input.value = value;
                input.setAttribute('readonly', 'readonly');
                input.style.position = 'fixed';
                input.style.opacity = '0';
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
            }

            this.copied = true;
            window.setTimeout(() => { this.copied = false; }, 1200);
        },
    }"
>
    <a class="record-ref-link" href="{{ $href }}" wire:navigate>
        {{ $value ?: '-' }}
    </a>
    @if ($value)
        <button
            type="button"
            class="record-ref-copy"
            data-copy-icon="square-2-stack"
            x-bind:class="{ 'is-copied': copied }"
            x-on:click.stop.prevent="copy(@js($value))"
            x-bind:title="copied ? @js($copiedLabel) : @js($copyLabel)"
            aria-label="{{ $copyLabel }} {{ $value }}"
        >
            <flux:icon.square-2-stack x-show="! copied" />
            <flux:icon.check x-cloak x-show="copied" />
        </button>
    @endif
</span>
