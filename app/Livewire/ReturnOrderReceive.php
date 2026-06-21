<?php

namespace App\Livewire;

use App\Models\ReturnOrder;
use App\Models\ReturnOrderLine;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ReturnOrderReceive extends Component
{
    public int $returnOrderId = 0; public string $collect_amount = ''; public array $lineDrafts = []; public array $newLines = [];
    public function mount(ReturnOrder $returnOrder): void { $this->staffOnly(); $this->returnOrderId=$returnOrder->id; $this->fillDrafts($returnOrder->load('lines')); }
    public function addUnexpectedLine(): void { $this->newLines[] = ['sku_id'=>'','received_qty'=>'1','received_location_id'=>'','note'=>'']; }
    public function saveReceive()
    {
        $this->staffOnly(); $order=$this->orderQuery()->with('lines')->findOrFail($this->returnOrderId);
        foreach($order->lines as $line){$draft=$this->lineDrafts[$line->id]??[]; $line->update(['received_qty'=>(int)($draft['received_qty']??0),'received_location_id'=>$this->intOrNull($draft['received_location_id']??''),'received_at'=>now()]);}
        foreach($this->newLines as $line){ if(($line['sku_id']??'')===''){continue;} $sku=Sku::query()->where('tenant_id',$order->tenant_id)->findOrFail($line['sku_id']); $order->lines()->create(['tenant_id'=>$order->tenant_id,'sku_id'=>$sku->id,'stock_item_id'=>$sku->stock_item_id,'expected_qty'=>0,'received_qty'=>(int)($line['received_qty']??0),'received_location_id'=>$this->intOrNull($line['received_location_id']??''),'note'=>$line['note']??null,'received_at'=>now()]); }
        $order->update(['collect_amount'=>$this->collect_amount===''?null:$this->collect_amount,'received_at'=>now(),'received_by_user_id'=>Auth::id(),'status'=>ReturnOrder::STATUS_RECEIVED]);
        session()->flash('status', __('return_orders.received')); return redirect()->route('return-orders.show',$order);
    }
    public function render(){ $order=$this->orderQuery()->with('lines')->findOrFail($this->returnOrderId); return view('livewire.return-order-receive',['order'=>$order,'skus'=>Sku::query()->where('tenant_id',$order->tenant_id)->whereNotNull('stock_item_id')->orderBy('sku')->get(['id','sku','name','stock_item_id']),'locations'=>WarehouseLocation::query()->where('warehouse_id',$order->warehouse_id)->orderBy('code')->get(['id','code','name'])])->layout('inventory',['title'=>__('return_orders.receive_page_title'),'subtitle'=>$order->return_no]); }
    private function fillDrafts(ReturnOrder $order): void { $this->collect_amount=(string)$order->collect_amount; foreach($order->lines as $line){$this->lineDrafts[$line->id]=['received_qty'=>(string)($line->received_qty ?: $line->expected_qty),'received_location_id'=>(string)$line->received_location_id];} }
    private function orderQuery(){return ReturnOrder::query()->whereIn('tenant_id',$this->allowedTenantIds());}
    private function isInternalUser(): bool{return Auth::user()?->user_type==='internal';} private function staffOnly(): void{if(!$this->isInternalUser()){abort(403);}} private function allowedTenantIds(): array{return $this->isInternalUser()?Tenant::query()->pluck('id')->all():(Auth::user()?->tenantUsers()->where('status','active')->pluck('tenant_id')->all()??[]);} private function intOrNull($v): ?int{$v=trim((string)$v);return $v===''?null:(int)$v;}
}

