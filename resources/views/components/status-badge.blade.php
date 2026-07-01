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
        'reship_in_progress' => 'amber',
        'adjusted',
        'announced',
        'arranged',
        'draft',
        'inspected',
        'open',
        'partially_received',
        'pending',
        'reserved',
        'ship_ready',
        'unfulfilled' => 'blue',
        'no_change' => 'zinc',
        'reshipped' => 'green',
        'inactive' => 'zinc',
        default => 'zinc',
    };
@endphp

<flux:badge color="{{ $color }}">{{ $label ?? $status }}</flux:badge>
