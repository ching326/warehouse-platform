@props([
    'options',
])

<select wire:model.live="perPage" class="rows-per-page-select" aria-label="{{ __('common.rows_per_page') }}">
    @foreach ($options as $option)
        <option value="{{ $option }}">{{ $option }}</option>
    @endforeach
</select>
