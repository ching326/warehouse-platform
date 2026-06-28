@if ($stockItem)
    @php
        $primaryImage = $stockItem->primaryImage;
    @endphp
    @if ($primaryImage)
        @if ($interactive ?? false)
            <button type="button" class="product-thumbnail product-thumbnail-button" wire:click="openImagePanel({{ $stockItem->id }})" aria-label="{{ __('skus.manage_images') }}">
                <img src="{{ $this->mediaUrl($primaryImage) }}" alt="{{ $primaryImage->file_name }}">
            </button>
        @else
            <img class="product-thumbnail" src="{{ $this->mediaUrl($primaryImage) }}" alt="{{ $primaryImage->file_name }}">
        @endif
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
