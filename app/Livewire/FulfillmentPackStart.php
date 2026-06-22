<?php

namespace App\Livewire;

use App\Models\Tenant;
use App\Services\Fulfillment\FulfillmentPackService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class FulfillmentPackStart extends Component
{
    public string $scan = '';

    public ?string $message = null;

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeInternalUser();
    }

    public function search(FulfillmentPackService $service)
    {
        $this->authorizeInternalUser();

        $result = $service->findGroupForScan($this->scan, $this->allowedTenantIds());
        $this->scan = '';

        if ($result->status === 'found' && $result->group) {
            return $this->redirectRoute('fulfillment-groups.pack', $result->group, navigate: true);
        }

        $this->message = match ($result->status) {
            'multiple' => __('fulfillment_pack.multiple_matches'),
            'already_shipped' => __('fulfillment_pack.already_shipped'),
            'cancelled' => __('fulfillment_pack.cancelled_group'),
            default => __('fulfillment_pack.not_found'),
        };

        $this->dispatch('pack-scan-focus');

        return null;
    }

    public function render()
    {
        $this->authorizeInternalUser();

        return view('livewire.fulfillment-pack-start')
            ->layout('inventory', [
                'title' => __('fulfillment_pack.start_page_title'),
                'subtitle' => __('fulfillment_pack.page_title'),
            ]);
    }

    private function authorizeInternalUser(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
    }
}
