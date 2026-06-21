<?php

namespace App\Livewire;

use App\Models\ReturnOrder;
use App\Models\ReturnOrderCost;
use App\Models\ReturnOrderLine;
use App\Models\Tenant;
use App\Models\WarehouseLocation;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ReturnOrderDisposition extends Component
{
    public int $returnOrderId=0; public array $lineDrafts=[]; public string $cost_type=ReturnOrderCost::COST_OTHER; public string $cost_amount=''; public string $cost_note='';
    public function mount(ReturnOrder $returnOrder): void{ $this->staffOnly(); $this->returnOrderId=$returnOrder->id; foreach($returnOrder->lines as $line){$this->lineDrafts[$line->id]=['disposition'=>$line->disposition,'disposition_location_id'=>(string)$line->disposition_location_id];} }
    public function confirmDisposition(InventoryService $inventory)
    {
        $this->staffOnly(); $order=$this->query()->with('lines')->findOrFail($this->returnOrderId);
        DB::transaction(function() use($order,$inventory): void { $hasUndecided=false; foreach($order->lines as $line){$draft=$this->lineDrafts[$line->id]??[]; $disposition=$draft['disposition']??ReturnOrderLine::DISPOSITION_UNDECIDED; $line->update(['disposition'=>$disposition,'disposition_location_id'=>$this->intOrNull($draft['disposition_location_id']??''),'dispositioned_at'=>$disposition===ReturnOrderLine::DISPOSITION_UNDECIDED?null:now()]); if($disposition===ReturnOrderLine::DISPOSITION_UNDECIDED){$hasUndecided=true; continue;} $this->applyInventory($inventory,$order,$line->refresh()); } if($this->cost_amount!==''){$order->costs()->create(['tenant_id'=>$order->tenant_id,'cost_type'=>$this->cost_type,'amount'=>$this->cost_amount,'currency'=>'JPY','note'=>$this->cost_note?:null,'created_by_user_id'=>Auth::id()]);} $order->update(['status'=>$hasUndecided?ReturnOrder::STATUS_AWAITING_DISPOSITION:ReturnOrder::STATUS_DISPOSITIONED,'dispositioned_at'=>$hasUndecided?null:now(),'dispositioned_by_user_id'=>Auth::id()]); });
        session()->flash('status',__('return_orders.dispositioned')); return redirect()->route('return-orders.show',$order);
    }
    private function applyInventory(InventoryService $inventory, ReturnOrder $order, ReturnOrderLine $line): void { $qty=(int)$line->received_qty; if($qty<=0 || !$line->stock_item_id || !$order->warehouse_id){return;} $ctx=['ref_type'=>'return_order','ref_id'=>(string)$order->id,'user_id'=>Auth::id()]; if($line->disposition===ReturnOrderLine::DISPOSITION_RETURN_TO_INVENTORY){$inventory->receiveStock($order->tenant_id,$order->warehouse_id,$line->stock_item_id,$qty,$ctx);} elseif($line->disposition===ReturnOrderLine::DISPOSITION_MARK_DAMAGED){$inventory->receiveStock($order->tenant_id,$order->warehouse_id,$line->stock_item_id,$qty,$ctx);$inventory->markDamaged($order->tenant_id,$order->warehouse_id,$line->stock_item_id,$qty,$ctx);} elseif($line->disposition===ReturnOrderLine::DISPOSITION_HOLD_QUARANTINE){$inventory->receiveStock($order->tenant_id,$order->warehouse_id,$line->stock_item_id,$qty,$ctx);$inventory->placeHold($order->tenant_id,$order->warehouse_id,$line->stock_item_id,$qty,$ctx);} }
    public function render(){ $order=$this->query()->with('lines.sku','lines.stockItem')->findOrFail($this->returnOrderId); return view('livewire.return-order-disposition',['order'=>$order,'dispositions'=>ReturnOrderLine::dispositionOptions(),'costTypes'=>ReturnOrderCost::costTypeOptions(),'locations'=>WarehouseLocation::query()->where('warehouse_id',$order->warehouse_id)->orderBy('code')->get(['id','code','name'])])->layout('inventory',['title'=>__('return_orders.disposition_page_title'),'subtitle'=>$order->return_no]); }
    private function query(){return ReturnOrder::query()->whereIn('tenant_id',$this->allowedTenantIds());} private function isInternalUser(): bool{return Auth::user()?->user_type==='internal';} private function staffOnly(): void{if(!$this->isInternalUser()){abort(403);}} private function allowedTenantIds(): array{return $this->isInternalUser()?Tenant::query()->pluck('id')->all():(Auth::user()?->tenantUsers()->where('status','active')->pluck('tenant_id')->all()??[]);} private function intOrNull($v): ?int{$v=trim((string)$v);return $v===''?null:(int)$v;}
}

