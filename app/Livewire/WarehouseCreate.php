<?php

namespace App\Livewire;

use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class WarehouseCreate extends Component
{
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

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    public function save()
    {
        $this->code = strtoupper(trim($this->code));
        $this->countryCode = strtoupper(trim($this->countryCode));

        $this->validateInput();

        Warehouse::create([
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

        session()->flash('status', __('setup.warehouse_created'));

        return redirect()->route('setup.warehouses.index');
    }

    public function render()
    {
        return view('livewire.warehouse-create', [
            'statuses' => [
                'active' => __('setup.status_active'),
                'inactive' => __('setup.status_inactive'),
            ],
        ])->layout('inventory', [
            'title' => __('setup.warehouse_create_page_title'),
            'subtitle' => __('setup.warehouse_create_page_subtitle'),
        ]);
    }

    private function validateInput(): void
    {
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
            'code' => ['required', 'string', 'max:50', Rule::unique('warehouses', 'code')],
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
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
