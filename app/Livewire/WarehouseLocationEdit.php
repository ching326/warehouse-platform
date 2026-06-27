<?php

namespace App\Livewire;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class WarehouseLocationEdit extends Component
{
    public WarehouseLocation $location;

    public string $warehouseId = '';

    public string $code = '';

    public string $name = '';

    public string $zoneType = 'storage';

    public string $storageUnitType = '';

    public string $status = 'active';

    public string $note = '';

    public function mount(WarehouseLocation $location): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }

        $this->location = $location;
        $this->warehouseId = (string) $location->warehouse_id;
        $this->code = $location->code;
        $this->name = $location->name ?? '';
        $this->zoneType = $location->zone_type;
        $this->storageUnitType = $location->storage_unit_type ?? '';
        $this->status = $location->status;
        $this->note = $location->note ?? '';
    }

    public function save()
    {
        $code = strtoupper(trim($this->code));

        validator([
            'warehouse_id' => $this->warehouseId,
            'code' => $code,
            'name' => $this->name,
            'zone_type' => $this->zoneType,
            'storage_unit_type' => $this->storageUnitType,
            'status' => $this->status,
            'note' => $this->note,
        ], [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('warehouse_locations', 'code')
                    ->where('warehouse_id', (int) $this->warehouseId)
                    ->ignore($this->location->id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'zone_type' => ['required', 'string', Rule::in(array_keys($this->zoneTypes()))],
            'storage_unit_type' => ['nullable', 'string', Rule::in(array_keys($this->storageUnitTypes()))],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'code.unique' => __('locations.duplicate_code'),
        ])->validate();

        $this->location->update([
            'warehouse_id' => (int) $this->warehouseId,
            'code' => $code,
            'name' => $this->nullableString($this->name),
            'zone_type' => $this->zoneType,
            'storage_unit_type' => $this->nullableString($this->storageUnitType),
            'status' => $this->status,
            'note' => $this->nullableString($this->note),
        ]);

        session()->flash('status', __('locations.location_updated'));

        return redirect()->route('setup.locations.index');
    }

    public function render()
    {
        return view('livewire.warehouse-location-edit', [
            'warehouses' => Warehouse::query()
                ->where(function ($query) {
                    $query
                        ->where('status', 'active')
                        ->orWhere('id', $this->location->warehouse_id);
                })
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'zoneTypes' => $this->zoneTypes(),
            'storageUnitTypes' => $this->storageUnitTypes(),
            'statuses' => [
                'active' => __('locations.status_active'),
                'inactive' => __('locations.status_inactive'),
            ],
        ])->layout('inventory', [
            'title' => __('locations.edit_page_title'),
            'subtitle' => $this->location->warehouse->code.' / '.$this->location->code,
        ]);
    }

    private function zoneTypes(): array
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

    private function storageUnitTypes(): array
    {
        return [
            'bin' => __('locations.storage_unit_bin'),
            'rack' => __('locations.storage_unit_rack'),
            'shelf' => __('locations.storage_unit_shelf'),
            'pallet' => __('locations.storage_unit_pallet'),
            'cage' => __('locations.storage_unit_cage'),
            'floor' => __('locations.storage_unit_floor'),
            'room' => __('locations.storage_unit_room'),
            'other' => __('locations.storage_unit_other'),
        ];
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
