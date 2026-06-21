@if (session('status') || session('error'))
    <div
        @class([
            'app-toast-message',
            'app-toast-message-success' => session('status'),
            'app-toast-message-error' => session('error'),
        ])
        wire:key="app-toast-{{ \Illuminate\Support\Str::uuid() }}"
        x-data="{ show: true }"
        x-show="show"
        x-init="@if (session('status')) setTimeout(() => show = false, 1500) @endif"
        x-transition.opacity.duration.150ms
    >
        <span>{{ session('status') ?: session('error') }}</span>

        @if (session('error'))
            <button type="button" aria-label="Close" x-on:click="show = false">x</button>
        @endif
    </div>
@endif
