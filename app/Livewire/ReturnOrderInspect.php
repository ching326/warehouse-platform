<?php

namespace App\Livewire;

use App\Models\ReturnOrder;
use App\Models\ReturnOrderLine;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ReturnOrderInspect extends Component
{
    public int $returnOrderId=0; public array $lineDrafts=[];
    public function mount(ReturnOrder $returnOrder): void{ $this->staffOnly(); $this->returnOrderId=$returnOrder->id; foreach($returnOrder->lines as $line){$this->lineDrafts[$line->id]=['condition'=>$line->condition,'note'=>(string)$line->note];} }
    public function saveInspect(){ $this->staffOnly(); $order=$this->query()->with('lines')->findOrFail($this->returnOrderId); foreach($order->lines as $line){$draft=$this->lineDrafts[$line->id]??[]; $line->update(['condition'=>$draft['condition']??ReturnOrderLine::CONDITION_UNKNOWN,'note'=>$draft['note']??null,'inspected_at'=>now()]);} $order->update(['status'=>ReturnOrder::STATUS_AWAITING_DISPOSITION,'inspected_at'=>now(),'inspected_by_user_id'=>Auth::id()]); session()->flash('status',__('return_orders.inspected')); return redirect()->route('return-orders.show',$order); }
    public function render(){ $order=$this->query()->with('lines.sku','lines.stockItem')->findOrFail($this->returnOrderId); return view('livewire.return-order-inspect',['order'=>$order,'conditions'=>ReturnOrderLine::conditionOptions()])->layout('inventory',['title'=>__('return_orders.inspect_page_title'),'subtitle'=>$order->return_no]); }
    private function query(){return ReturnOrder::query()->whereIn('tenant_id',$this->allowedTenantIds());} private function isInternalUser(): bool{return Auth::user()?->user_type==='internal';} private function staffOnly(): void{if(!$this->isInternalUser()){abort(403);}} private function allowedTenantIds(): array{return $this->isInternalUser()?Tenant::query()->pluck('id')->all():(Auth::user()?->tenantUsers()->where('status','active')->pluck('tenant_id')->all()??[]);}
}

