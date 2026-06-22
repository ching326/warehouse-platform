@if ($stockItem)
    @php($primaryImage = $stockItem->primaryImage)
    @if ($primaryImage)
        <img class="product-thumbnail" src="{{ \Illuminate\Support\Facades\Storage::disk($primaryImage->disk)->url($primaryImage->path) }}" alt="{{ $primaryImage->file_name }}">
    @elseif ($interactive ?? false)
        <button type="button" class="product-thumbnail product-thumbnail-placeholder" wire:click="openImagePanel({{ $stockItem->id }})" aria-label="{{ __('skus.upload_image') }}">
            +
        </button>
    @else
        <span class="product-thumbnail product-thumbnail-placeholder" aria-hidden="true"></span>
    @endif
@else
    <span class="muted-dash">-</span>
@endif
