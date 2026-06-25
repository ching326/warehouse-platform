<?php

namespace App\Livewire;

use App\Models\PackagingMaterial;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class PackagingMaterialCreate extends Component
{
    public string $code = '';

    public string $name = '';

    public string $type = '';

    public string $status = 'active';

    public string $dimensionUnit = 'cm';

    public string $lengthValue = '';

    public string $widthValue = '';

    public string $heightValue = '';

    public string $weightUnit = 'g';

    public string $weightValue = '';

    public string $cost = '';

    public string $currency = 'JPY';

    public string $note = '';

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    public function save()
    {
        $this->code = strtoupper(trim($this->code));

        $this->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('packaging_materials', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['box', 'bag', 'envelope', 'tube', 'other'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'dimensionUnit' => ['required', Rule::in(['cm', 'mm', 'in'])],
            'lengthValue' => ['nullable', 'numeric', 'min:0'],
            'widthValue' => ['nullable', 'numeric', 'min:0'],
            'heightValue' => ['nullable', 'numeric', 'min:0'],
            'weightUnit' => ['required', Rule::in(['g', 'kg'])],
            'weightValue' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', Rule::in(['JPY', 'CNY', 'USD', 'HKD'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        PackagingMaterial::create([
            'code' => $this->code,
            'name' => trim($this->name),
            'type' => $this->type,
            'status' => $this->status,
            'dimension_unit' => $this->dimensionUnit,
            'length_value' => $this->lengthValue !== '' ? $this->lengthValue : null,
            'width_value' => $this->widthValue !== '' ? $this->widthValue : null,
            'height_value' => $this->heightValue !== '' ? $this->heightValue : null,
            'weight_unit' => $this->weightUnit,
            'weight_value' => $this->weightValue !== '' ? $this->weightValue : null,
            'cost' => $this->cost !== '' ? $this->cost : null,
            'currency' => $this->currency,
            'note' => $this->note !== '' ? $this->note : null,
        ]);

        session()->flash('status', __('setup.packaging_created'));

        return redirect()->route('setup.packagings.index');
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    public function render()
    {
        return view('livewire.packaging-material-create', [
            'types' => [
                'box' => __('setup.packaging_types.box'),
                'bag' => __('setup.packaging_types.bag'),
                'envelope' => __('setup.packaging_types.envelope'),
                'tube' => __('setup.packaging_types.tube'),
                'other' => __('setup.packaging_types.other'),
            ],
            'statuses' => [
                'active' => __('setup.status_active'),
                'inactive' => __('setup.status_inactive'),
            ],
        ])->layout('inventory', [
            'title' => __('setup.packaging_create_page_title'),
            'subtitle' => __('setup.packaging_create_page_subtitle'),
        ]);
    }
}
