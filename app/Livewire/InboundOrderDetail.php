<?php

namespace App\Livewire;

use App\Livewire\Concerns\HandlesPrivateMediaAssets;
use App\Models\InboundOrder;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class InboundOrderDetail extends Component
{
    use HandlesPrivateMediaAssets;
    use WithFileUploads;

    public int $orderId = 0;

    public ?TemporaryUploadedFile $document = null;

    private bool $visibleTenantIdsResolved = false;

    private ?array $visibleTenantIdsCache = null;

    public function mount(InboundOrder $order): void
    {
        $this->orderId = $this->scopedOrderQuery()->findOrFail($order->id)->id;
    }

    public function markArrived(): void
    {
        $order = $this->scopedOrderQuery()
            ->where('status', InboundOrder::STATUS_PENDING)
            ->findOrFail($this->orderId);

        $order->update([
            'status' => InboundOrder::STATUS_ARRIVED,
            'arrived_at' => now(),
            'arrived_by_user_id' => Auth::id(),
        ]);

        session()->flash('status', __('inbound.order_arrived'));
    }

    public function cancel(): void
    {
        $order = $this->scopedOrderQuery()
            ->with('lines:id,inbound_order_id,received_qty')
            ->whereIn('status', [InboundOrder::STATUS_PENDING, InboundOrder::STATUS_ARRIVED])
            ->findOrFail($this->orderId);

        if ($order->lines->contains(fn ($line) => $line->received_qty > 0)) {
            session()->flash('error', __('inbound.cannot_cancel_after_receiving'));

            return;
        }

        DB::transaction(function () use ($order): void {
            $order->update(['status' => InboundOrder::STATUS_CANCELLED]);
        });

        session()->flash('status', __('inbound.order_cancelled_detail'));
    }

    public function statusLabel(string $status): string
    {
        return __('inbound.status_'.$status);
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            InboundOrder::STATUS_PENDING => 'amber',
            InboundOrder::STATUS_ARRIVED => 'blue',
            InboundOrder::STATUS_PARTIALLY_RECEIVED => 'indigo',
            InboundOrder::STATUS_RECEIVED => 'green',
            InboundOrder::STATUS_CANCELLED => 'zinc',
            default => 'zinc',
        };
    }

    public function canCancel(InboundOrder $order): bool
    {
        return in_array($order->status, [InboundOrder::STATUS_PENDING, InboundOrder::STATUS_ARRIVED], true)
            && ! $order->lines->contains(fn ($line) => $line->received_qty > 0);
    }

    public function canReceive(InboundOrder $order): bool
    {
        return in_array($order->status, [InboundOrder::STATUS_ARRIVED, InboundOrder::STATUS_PARTIALLY_RECEIVED], true);
    }

    public function uploadDocument(): void
    {
        $order = $this->scopedOrderQuery()->findOrFail($this->orderId);

        $this->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $this->createPrivateMediaAsset(
            $order,
            $this->document,
            MediaAsset::MODEL_TYPE_INBOUND_ORDER,
            'document',
            'media/private/tenant-'.$order->tenant_id.'/inbound-orders/'.$order->id,
            'inbound_order',
        );

        $this->document = null;
        session()->flash('status', __('inbound.document_uploaded'));
    }

    public function deleteDocument(int $mediaAssetId): void
    {
        $order = $this->scopedOrderQuery()
            ->with('mediaAssets')
            ->findOrFail($this->orderId);
        $asset = $order->mediaAssets->firstWhere('id', $mediaAssetId);

        if (! $asset) {
            abort(404);
        }

        $this->deletePrivateMediaAsset($asset, $order, 'inbound_order');
        session()->flash('status', __('inbound.document_deleted'));
    }

    public function render()
    {
        $order = $this->scopedOrderQuery()
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'createdBy:id,name',
                'arrivedBy:id,name',
                'receivedBy:id,name',
                'lines.sku:id,sku,name',
                'lines.stockItem:id,code,name',
                'lines.receipts.warehouseLocation:id,code,name',
                'lines.receipts.receivedBy:id,name',
                'mediaAssets',
            ])
            ->findOrFail($this->orderId);

        return view('livewire.inbound-order-detail', [
            'order' => $order,
        ])->layout('inventory', [
            'title' => __('inbound.detail_page_title'),
            'subtitle' => __('inbound.detail_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function scopedOrderQuery(): Builder
    {
        return InboundOrder::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()));
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function visibleTenantIds(): ?array
    {
        if ($this->visibleTenantIdsResolved) {
            return $this->visibleTenantIdsCache;
        }

        $this->visibleTenantIdsResolved = true;
        $user = Auth::user();

        if (! $user) {
            return $this->visibleTenantIdsCache = [];
        }

        if ($this->isInternalUser()) {
            return $this->visibleTenantIdsCache = null;
        }

        return $this->visibleTenantIdsCache = $user->activeTenantIds();
    }

    protected function privateMediaFileProperty(): string
    {
        return 'document';
    }
}
