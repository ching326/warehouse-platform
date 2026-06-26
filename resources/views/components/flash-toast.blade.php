@php($toastType = session('error') ? 'error' : (session('warning') ? 'warning' : (session('status') ? 'success' : null)))
@php($toastMessage = session('error') ?: (session('warning') ?: session('status')))
@if ($toastType)
    <div
        class="app-toast app-toast-{{ $toastType }}"
        wire:key="app-toast-{{ \Illuminate\Support\Str::uuid() }}"
        x-data="{ show: true }"
        x-show="show"
        x-init="@if ($toastType === 'success') setTimeout(() => show = false, 2500) @endif"
        x-transition.opacity.duration.150ms
        role="alert"
    >
        <div class="app-toast-body">
            <strong class="app-toast-title">{{ __('common.toast.'.$toastType) }}</strong>
            <span class="app-toast-text">{{ $toastMessage }}</span>
        </div>
        <button type="button" class="app-toast-close" aria-label="{{ __('common.toast.close') }}" x-on:click="show = false">&times;</button>
    </div>
@endif
