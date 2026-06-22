<?php

namespace App\Livewire;

use App\Livewire\Concerns\HandlesPrivateMediaAssets;
use App\Models\MediaAsset;
use App\Models\ReturnOrder;
use App\Models\ReturnOrderCost;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ReturnOrderShow extends Component
{
    use HandlesPrivateMediaAssets;
    use WithFileUploads;

    public int $returnOrderId = 0;
    public string $cost_type = ReturnOrderCost::COST_OTHER;
    public string $cost_amount = '';
    public string $cost_note = '';
    public ?TemporaryUploadedFile $photo = null;
    public string $photoType = 'damage';

    public function mount(ReturnOrder $returnOrder): void
    {
        if (! in_array($returnOrder->tenant_id, $this->allowedTenantIds(), true)) { abort(403); }
        $this->returnOrderId = $returnOrder->id;
    }

    public function markArrived(): void
    {
        $order = $this->order();
        $this->staffOnly();
        if ($order->isClosed()) { return; }
        $order->update(['status' => ReturnOrder::STATUS_ARRIVED, 'arrived_at' => now(), 'arrived_by_user_id' => Auth::id()]);
        session()->flash('status', __('return_orders.arrived'));
    }

    public function closeReturn(): void
    {
        $order = $this->order();
        $this->staffOnly();
        $order->update(['status' => ReturnOrder::STATUS_CLOSED, 'closed_at' => now()]);
        session()->flash('status', __('return_orders.closed'));
    }

    public function cancelReturn(): void
    {
        $order = $this->order();
        if (! in_array($order->status, [ReturnOrder::STATUS_DRAFT, ReturnOrder::STATUS_ANNOUNCED], true)) { return; }
        $order->update(['status' => ReturnOrder::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => Auth::id()]);
        session()->flash('status', __('return_orders.cancelled'));
    }

    public function addCost(): void
    {
        $this->staffOnly();
        $order = $this->order();
        validator(['cost_type'=>$this->cost_type,'amount'=>$this->cost_amount], ['cost_type'=>['required','in:'.implode(',', array_keys(ReturnOrderCost::costTypeOptions()))], 'amount'=>['required','numeric','min:0']])->validate();
        $order->costs()->create(['tenant_id'=>$order->tenant_id,'cost_type'=>$this->cost_type,'amount'=>$this->cost_amount,'currency'=>'JPY','note'=>$this->cost_note ?: null,'created_by_user_id'=>Auth::id()]);
        $this->cost_amount = $this->cost_note = '';
        session()->flash('status', __('return_orders.cost_added'));
    }

    public function uploadPhoto(): void
    {
        $order = $this->order();

        $this->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photoType' => ['required', Rule::in(['damage', 'other'])],
        ]);

        $this->createPrivateMediaAsset(
            $order,
            $this->photo,
            MediaAsset::MODEL_TYPE_RETURN_ORDER,
            $this->photoType,
            'media/private/tenant-'.$order->tenant_id.'/return-orders/'.$order->id,
            'return_order',
        );

        $this->resetPhotoForm();
        session()->flash('status', __('media.image_uploaded'));
    }

    public function deletePhoto(int $mediaAssetId): void
    {
        $order = $this->query()->with('mediaAssets')->findOrFail($this->returnOrderId);
        $asset = $order->mediaAssets->firstWhere('id', $mediaAssetId);

        if (! $asset) {
            abort(404);
        }

        $this->deletePrivateMediaAsset($asset, $order, 'return_order');
        session()->flash('status', __('media.image_deleted'));
    }

    public function render()
    {
        $order = $this->query()->with(['tenant','warehouse','issue','salesOrder','outboundOrder','fulfillmentGroup','createdBy','lines.sku','lines.stockItem','costs.createdBy','mediaAssets'])->findOrFail($this->returnOrderId);
        return view('livewire.return-order-show', ['order'=>$order, 'costTypes'=>ReturnOrderCost::costTypeOptions(), 'isInternal'=>$this->isInternalUser(), 'photoTypes'=>$this->photoTypeOptions()])->layout('inventory', ['title'=>$order->return_no, 'subtitle'=>__('return_orders.detail_page_subtitle')]);
    }
    private function order(): ReturnOrder { return $this->query()->findOrFail($this->returnOrderId); }
    private function query() { return ReturnOrder::query()->whereIn('tenant_id', $this->allowedTenantIds()); }
    private function isInternalUser(): bool { return Auth::user()?->user_type === 'internal'; }
    private function staffOnly(): void { if (! $this->isInternalUser()) { abort(403); } }
    private function allowedTenantIds(): array { return $this->isInternalUser() ? Tenant::query()->pluck('id')->all() : (Auth::user()?->activeTenantIds() ?? []); }
    private function resetPhotoForm(): void { $this->photo = null; $this->photoType = 'damage'; }
    private function photoTypeOptions(): array { return ['damage' => __('media.type_damage'), 'other' => __('media.type_other')]; }
}

