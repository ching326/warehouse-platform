<?php

namespace App\Livewire;

use App\Models\ProductType;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ProductTypeSettings extends Component
{
    public array $types = [];

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }

        $this->loadTypes();
    }

    public function addType(): void
    {
        $this->types[] = [
            'id' => null,
            'slug' => '',
            'name' => '',
            'sort_order' => count($this->types) * 10,
            'translations' => ['en' => '', 'zh_TW' => '', 'zh_CN' => '', 'ja' => ''],
        ];
    }

    public function removeType(int $index): void
    {
        array_splice($this->types, $index, 1);
        $this->types = array_values($this->types);
    }

    public function save(): void
    {
        $this->validate([
            'types.*.slug' => ['required', 'string', 'max:50'],
            'types.*.name' => ['required', 'string', 'max:255'],
            'types.*.sort_order' => ['required', 'integer', 'min:0'],
            'types.*.translations.en' => ['nullable', 'string', 'max:255'],
            'types.*.translations.zh_TW' => ['nullable', 'string', 'max:255'],
            'types.*.translations.zh_CN' => ['nullable', 'string', 'max:255'],
            'types.*.translations.ja' => ['nullable', 'string', 'max:255'],
        ]);

        $keptIds = collect($this->types)->pluck('id')->filter()->values()->all();
        ProductType::whereNotIn('id', $keptIds)->delete();

        foreach ($this->types as $row) {
            $translations = array_filter(
                $row['translations'] ?? [],
                fn ($v) => $v !== null && $v !== ''
            );

            $enName = $translations['en'] ?? $row['name'];

            $data = [
                'slug' => $row['slug'],
                'name' => $enName ?: $row['name'],
                'sort_order' => (int) $row['sort_order'],
                'translations' => $translations ?: null,
            ];

            if ($row['id']) {
                ProductType::find($row['id'])?->update($data);
            } else {
                ProductType::create($data);
            }
        }

        $this->loadTypes();
        session()->flash('saved', true);
    }

    public function render()
    {
        return view('livewire.product-type-settings')
            ->layout('inventory', [
                'title' => __('setup.product_types_title'),
                'subtitle' => __('setup.product_types_hint'),
            ]);
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function loadTypes(): void
    {
        $this->types = ProductType::orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'name' => $t->name,
                'sort_order' => $t->sort_order,
                'translations' => array_merge(
                    ['en' => '', 'zh_TW' => '', 'zh_CN' => '', 'ja' => ''],
                    $t->translations ?? []
                ),
            ])
            ->all();
    }
}
