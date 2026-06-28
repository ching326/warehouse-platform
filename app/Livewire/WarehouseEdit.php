<?php

namespace App\Livewire;

use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Component;

class WarehouseEdit extends Component
{
    public Warehouse $warehouse;

    public string $code = '';

    public string $name = '';

    public string $countryCode = '';

    public string $timezone = 'Asia/Tokyo';

    public string $postalCode = '';

    public string $state = '';

    public string $city = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $phone = '';

    public string $status = 'active';

    public function mount(Warehouse $warehouse): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }

        $this->warehouse = $warehouse;
        $this->code = $warehouse->code;
        $this->name = $warehouse->name;
        $this->countryCode = $warehouse->country_code;
        $this->timezone = $warehouse->timezone;
        $this->postalCode = $warehouse->postal_code ?? '';
        $this->state = $warehouse->state ?? '';
        $this->city = $warehouse->city ?? '';
        $this->addressLine1 = $warehouse->address_line1 ?? '';
        $this->addressLine2 = $warehouse->address_line2 ?? '';
        $this->phone = $warehouse->phone ?? '';
        $this->status = $warehouse->status;
    }

    public function save()
    {
        $this->code = strtoupper(trim($this->code));
        $this->countryCode = strtoupper(trim($this->countryCode));

        validator([
            'code' => $this->code,
            'name' => $this->name,
            'country_code' => $this->countryCode,
            'timezone' => $this->timezone,
            'postal_code' => $this->postalCode,
            'state' => $this->state,
            'city' => $this->city,
            'address_line1' => $this->addressLine1,
            'address_line2' => $this->addressLine2,
            'phone' => $this->phone,
            'status' => $this->status,
        ], [
            'code' => ['required', 'string', 'max:50', Rule::unique('warehouses', 'code')->ignore($this->warehouse->id)],
            'name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'timezone' => ['required', 'string', 'timezone'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
        ])->validate();

        $this->warehouse->update([
            'code' => $this->code,
            'name' => trim($this->name),
            'country_code' => $this->countryCode,
            'timezone' => $this->timezone,
            'postal_code' => $this->nullableString($this->postalCode),
            'state' => $this->nullableString($this->state),
            'city' => $this->nullableString($this->city),
            'address_line1' => $this->nullableString($this->addressLine1),
            'address_line2' => $this->nullableString($this->addressLine2),
            'phone' => $this->nullableString($this->phone),
            'status' => $this->status,
        ]);

        session()->flash('status', __('setup.warehouse_updated'));

        return redirect()->route('setup.warehouses.index');
    }

    public function delete()
    {
        if ($this->warehouseHasReferences()) {
            session()->flash('error', __('setup.warehouse_delete_failed'));

            return null;
        }

        $this->warehouse->delete();

        session()->flash('status', __('setup.warehouse_deleted'));

        return redirect()->route('setup.warehouses.index');
    }

    public function render()
    {
        return view('livewire.warehouse-edit', [
            'statuses' => [
                'active' => __('setup.status_active'),
                'inactive' => __('setup.status_inactive'),
            ],
            'timezones' => \DateTimeZone::listIdentifiers(),
        ])->layout('inventory', [
            'title' => __('setup.warehouse_edit_page_title'),
            'subtitle' => $this->warehouse->code.' - '.$this->warehouse->name,
        ]);
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

    private function warehouseHasReferences(): bool
    {
        $tables = [
            'warehouse_locations',
            'inventory_balances',
            'inventory_movements',
            'inbound_orders',
            'inbound_receipts',
            'outbound_orders',
            'return_orders',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('warehouse_id', $this->warehouse->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
