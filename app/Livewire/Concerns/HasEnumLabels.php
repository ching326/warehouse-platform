<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Lang;

trait HasEnumLabels
{
    private function enumLabel(string $group, string $value): string
    {
        $key = 'common.'.$group.'.'.$value;

        return Lang::has($key)
            ? __($key)
            : str($value)->replace('_', ' ')->title()->toString();
    }
}
