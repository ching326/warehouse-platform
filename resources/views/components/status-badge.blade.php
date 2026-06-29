@props([
    'status',
    'label' => null,
])

@php
    $statusKey = strtolower(str_replace([' ', '-'], '_', (string) $status));
    $color = match ($statusKey) {
        'active',
        'arrived',
        'closed',
        'completed',
        'received',
        'resolved',
        'shipped' => 'green',
        'cancelled',
        'canceled',
        'failed' => 'red',
        'on_hold' => 'amber',
        'announced',
        'draft',
        'inspected',
        'open',
        'partially_received',
        'pending',
        'reserved',
        'ship_ready',
        'unfulfilled' => 'blue',
        'inactive' => 'zinc',
        default => 'zinc',
    };
@endphp

<flux:badge color="{{ $color }}">{{ $label ?? $status }}</flux:badge>
