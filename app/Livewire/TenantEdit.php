<?php

namespace App\Livewire;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class TenantEdit extends Component
{
    public Tenant $tenant;

    public string $code = '';
    public string $name = '';
    public string $contactName = '';
    public string $contactEmail = '';
    public string $contactPhone = '';
    public string $billingTerms = '';
    public string $status = 'active';
    public string $notes = '';

    public function mount(Tenant $tenant): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }

        $this->tenant       = $tenant;
        $this->code         = $tenant->code;
        $this->name         = $tenant->name;
        $this->contactName  = $tenant->contact_name ?? '';
        $this->contactEmail = $tenant->contact_email ?? '';
        $this->contactPhone = $tenant->contact_phone ?? '';
        $this->billingTerms = $tenant->billing_terms ?? '';
        $this->status       = $tenant->status;
        $this->notes        = $tenant->notes ?? '';
    }

    public function save()
    {
        $this->code = strtoupper(trim($this->code));

        validator([
            'code'          => $this->code,
            'name'          => $this->name,
            'contact_name'  => $this->contactName,
            'contact_email' => $this->contactEmail,
            'contact_phone' => $this->contactPhone,
            'billing_terms' => $this->billingTerms,
            'status'        => $this->status,
            'notes'         => $this->notes,
        ], [
            'code'          => ['required', 'string', 'max:50', Rule::unique('tenants', 'code')->ignore($this->tenant->id)],
            'name'          => ['required', 'string', 'max:255'],
            'contact_name'  => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'billing_terms' => ['nullable', 'string', 'max:255'],
            'status'        => ['required', 'string', Rule::in(['active', 'inactive'])],
            'notes'         => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $this->tenant->update([
            'code'          => $this->code,
            'name'          => trim($this->name),
            'contact_name'  => $this->nullableString($this->contactName),
            'contact_email' => $this->nullableString($this->contactEmail),
            'contact_phone' => $this->nullableString($this->contactPhone),
            'billing_terms' => $this->nullableString($this->billingTerms),
            'status'        => $this->status,
            'notes'         => $this->nullableString($this->notes),
        ]);

        session()->flash('status', __('setup.tenant_updated'));

        return redirect()->route('setup.tenants.index');
    }

    public function render()
    {
        return view('livewire.tenant-edit', [
            'statuses' => [
                'active'   => __('setup.status_active'),
                'inactive' => __('setup.status_inactive'),
            ],
        ])->layout('inventory', [
            'title'    => __('setup.tenant_edit_page_title'),
            'subtitle' => $this->tenant->code.'  E'.$this->tenant->name,
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
}
