<?php

namespace App\Livewire;

use App\Models\FbaWarehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class FbaWarehouseEdit extends Component
{
    public FbaWarehouse $fbaWarehouse;

    public string $countryCode = 'JP';

    public string $code = '';

    public string $name = '';

    public string $postalCode = '';

    public string $state = '';

    public string $city = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $phone = '';

    public string $status = FbaWarehouse::STATUS_ACTIVE;

    public string $note = '';

    public function mount(FbaWarehouse $fbaWarehouse): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }

        $this->fbaWarehouse = $fbaWarehouse;
        $this->countryCode = $fbaWarehouse->country_code;
        $this->code = $fbaWarehouse->code;
        $this->name = $fbaWarehouse->name;
        $this->postalCode = $fbaWarehouse->postal_code ?? '';
        $this->state = $fbaWarehouse->state ?? '';
        $this->city = $fbaWarehouse->city ?? '';
        $this->addressLine1 = $fbaWarehouse->address_line1 ?? '';
        $this->addressLine2 = $fbaWarehouse->address_line2 ?? '';
        $this->phone = $fbaWarehouse->phone ?? '';
        $this->status = $fbaWarehouse->status;
        $this->note = $fbaWarehouse->note ?? '';
    }

    public function save()
    {
        $this->normalize();
        $this->validateInput();

        $this->fbaWarehouse->update($this->payload());

        session()->flash('status', __('setup.fba_warehouse_updated'));

        return redirect()->route('setup.fba-warehouses.index');
    }

    public function delete()
    {
        $this->fbaWarehouse->delete();

        session()->flash('status', __('setup.fba_warehouse_deleted'));

        return redirect()->route('setup.fba-warehouses.index');
    }

    public function render()
    {
        return view('livewire.fba-warehouse-edit', [
            'countries' => $this->countryOptions(),
            'statuses' => $this->statusOptions(),
        ])->layout('inventory', [
            'title' => __('setup.fba_warehouse_edit_page_title'),
            'subtitle' => $this->fbaWarehouse->code.' / '.$this->fbaWarehouse->name,
        ]);
    }

    private function validateInput(): void
    {
        validator($this->validationData(), [
            'country_code' => ['required', 'string', 'size:2', Rule::in(['JP'])],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('fba_warehouses', 'code')
                    ->where('country_code', $this->countryCode)
                    ->ignore($this->fbaWarehouse->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in([FbaWarehouse::STATUS_ACTIVE, FbaWarehouse::STATUS_INACTIVE])],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();
    }

    private function validationData(): array
    {
        return [
            'country_code' => $this->countryCode,
            'code' => $this->code,
            'name' => $this->name,
            'postal_code' => $this->postalCode,
            'state' => $this->state,
            'city' => $this->city,
            'address_line1' => $this->addressLine1,
            'address_line2' => $this->addressLine2,
            'phone' => $this->phone,
            'status' => $this->status,
            'note' => $this->note,
        ];
    }

    private function payload(): array
    {
        return [
            'country_code' => $this->countryCode,
            'code' => $this->code,
            'name' => trim($this->name),
            'postal_code' => $this->nullableString($this->postalCode),
            'state' => $this->nullableString($this->state),
            'city' => $this->nullableString($this->city),
            'address_line1' => $this->nullableString($this->addressLine1),
            'address_line2' => $this->nullableString($this->addressLine2),
            'phone' => $this->nullableString($this->phone),
            'status' => $this->status,
            'note' => $this->nullableString($this->note),
        ];
    }

    private function normalize(): void
    {
        $this->countryCode = strtoupper(trim($this->countryCode));
        $this->code = strtoupper(trim($this->code));
    }

    private function countryOptions(): array
    {
        return ['JP' => 'JP'];
    }

    private function statusOptions(): array
    {
        return [
            FbaWarehouse::STATUS_ACTIVE => __('setup.status_active'),
            FbaWarehouse::STATUS_INACTIVE => __('setup.status_inactive'),
        ];
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
