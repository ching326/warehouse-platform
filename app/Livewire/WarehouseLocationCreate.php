<?php

namespace App\Livewire;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class WarehouseLocationCreate extends Component
{
    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    public string $code = '';

    public string $name = '';

    public string $type = 'storage';

    public string $note = '';

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    public function save()
    {
        $code = $this->validateInput();

        WarehouseLocation::create([
            'warehouse_id' => (int) $this->warehouseId,
            'code' => $code,
            'name' => $this->nullableString($this->name),
            'type' => $this->type,
            'status' => 'active',
            'note' => $this->nullableString($this->note),
        ]);

        session()->flash('status', __('locations.location_created'));

        return redirect()->route('setup.locations.index');
    }

    public function render()
    {
        return view('livewire.warehouse-location-create', [
            'warehouses' => Warehouse::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'types' => $this->locationTypes(),
        ])->layout('inventory', [
            'title' => __('locations.create_page_title'),
            'subtitle' => __('locations.create_page_subtitle'),
        ]);
    }

    private function validateInput(): string
    {
        $code = strtoupper(trim($this->code));

        validator([
            'warehouse_id' => $this->warehouseId,
            'code' => $code,
            'name' => $this->name,
            'type' => $this->type,
            'note' => $this->note,
        ], [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('warehouse_locations', 'code')
                    ->where('warehouse_id', (int) $this->warehouseId),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(array_keys($this->locationTypes()))],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'code.unique' => __('locations.duplicate_code'),
        ])->validate();

        return $code;
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function locationTypes(): array
    {
        return [
            'storage' => __('locations.type_storage'),
            'receiving' => __('locations.type_receiving'),
            'qc' => __('locations.type_qc'),
            'packing' => __('locations.type_packing'),
            'shipping' => __('locations.type_shipping'),
            'hold' => __('locations.type_hold'),
            'damaged' => __('locations.type_damaged'),
            'other' => __('locations.type_other'),
        ];
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
