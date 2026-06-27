<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class OtherSettings extends Component
{
    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    public function render()
    {
        return view('livewire.other-settings')
            ->layout('inventory', [
                'title' => __('setup.other_settings_title'),
                'subtitle' => __('setup.other_settings_subtitle'),
            ]);
    }
}
