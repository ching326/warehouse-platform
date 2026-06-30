<?php

namespace App\Livewire;

use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Labels\SkuLabelContentResolver;
use App\Support\Labels\LabelLayout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SkuLabelPrint extends Component
{
    public const SESSION_KEY = 'sku_label_print_payload';

    public int $seedSkuId = 0;

    public string $layoutKey = '40up_a4';

    public array $entries = [];

    public int $applyQty = 1;

    public array $skipCells = [];

    public bool $useSkipCells = false;

    public bool $showSkipCellsModal = false;

    public function mount(?Sku $sku = null): void
    {
        $this->authorizeInternalUser();

        $skuIds = $this->seedSkuIds($sku);

        if ($skuIds === []) {
            abort(404);
        }

        $this->seedSkuId = $skuIds[0];
        $this->entries = collect($skuIds)
            ->map(fn (int $skuId): array => $this->defaultEntry($skuId))
            ->all();
    }

    public function applyQtyToAll(): void
    {
        $qty = max(1, (int) $this->applyQty);
        $this->applyQty = $qty;

        foreach ($this->entries as $index => $entry) {
            $this->entries[$index]['qty'] = $qty;
        }
    }

    public function addEntry(): void
    {
        if ($this->seedSkuId <= 0) {
            return;
        }

        $entry = $this->defaultEntry($this->seedSkuId);
        $entry['qty'] = max(1, (int) $this->applyQty);
        $this->entries[] = $entry;
    }

    public function removeEntry(int $index): void
    {
        if (count($this->entries) <= 1) {
            return;
        }

        unset($this->entries[$index]);
        $this->entries = array_values($this->entries);
    }

    public function toggleSkipCell(int $cell): void
    {
        $layout = $this->safeCurrentLayout();

        if (! $layout->supportsSkip() || $cell < 0 || $cell >= $layout->cellsPerPage()) {
            return;
        }

        $cells = collect($this->skipCells)
            ->map(fn ($value): int => (int) $value);

        $this->skipCells = $cells->contains($cell)
            ? $cells->reject(fn (int $value): bool => $value === $cell)->values()->all()
            : $cells->push($cell)->unique()->sort()->values()->all();
    }

    public function toggleSkipCellsMode(): void
    {
        $this->useSkipCells = ! $this->useSkipCells;

        if (! $this->useSkipCells) {
            $this->skipCells = [];
            $this->showSkipCellsModal = false;

            return;
        }

        $this->showSkipCellsModal = true;
    }

    public function closeSkipCellsModal(): void
    {
        $this->showSkipCellsModal = false;
    }

    public function updated($property): void
    {
        if (! is_string($property) || ! preg_match('/^entries\.(\d+)\.content$/', $property, $matches)) {
            return;
        }

        $index = (int) $matches[1];
        $entry = $this->entries[$index] ?? null;

        if (! is_array($entry)) {
            return;
        }

        $sku = $this->skuQuery()->find((int) ($entry['sku_id'] ?? 0));
        $content = (string) ($entry['content'] ?? '');
        $value = $sku ? app(SkuLabelContentResolver::class)->resolveValue($sku, $content) : null;

        $this->entries[$index]['value'] = $value ?? '';
    }

    public function generate(SkuLabelContentResolver $resolver)
    {
        $this->validateChoices($resolver);

        session()->put(self::SESSION_KEY, [
            'layoutKey' => $this->layoutKey,
            'entries' => collect($this->entries)->map(fn (array $entry): array => [
                'sku_id' => (int) $entry['sku_id'],
                'content' => (string) $entry['content'],
                'value' => (string) ($entry['value'] ?? ''),
                'name' => (string) ($entry['name'] ?? ''),
                'qty' => (int) $entry['qty'],
            ])->all(),
            'skipCells' => $this->useSkipCells ? array_values(array_map('intval', $this->skipCells)) : [],
        ]);

        return redirect()->route('skus.label.download');
    }

    /**
     * @return array<string, string>
     */
    public function layouts(): array
    {
        return collect(config('label_layouts', []))
            ->mapWithKeys(fn (array $layout, string $key): array => [$key => (string) $layout['name']])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function contentOptionsFor(int $skuId): array
    {
        $sku = $this->skuQuery()->find($skuId);

        return $sku ? app(SkuLabelContentResolver::class)->options($sku) : [];
    }

    public function currentLayout(): LabelLayout
    {
        return LabelLayout::fromConfig($this->layoutKey);
    }

    public function safeCurrentLayout(): LabelLayout
    {
        $layoutKeys = array_keys($this->layouts());
        $layoutKey = in_array($this->layoutKey, $layoutKeys, true) ? $this->layoutKey : '40up_a4';

        return LabelLayout::fromConfig($layoutKey);
    }

    public function render()
    {
        $skuIds = collect($this->entries)->pluck('sku_id')->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        $skus = $this->skuQuery()
            ->whereIn('id', $skuIds)
            ->with('stockItem')
            ->get()
            ->keyBy('id');
        $layout = $this->safeCurrentLayout();

        return view('livewire.sku-label-print', [
            'skus' => $skus,
            'layout' => $layout,
            'layoutOptions' => $this->layouts(),
        ])->layout('inventory', [
            'title' => __('skus.label_print_title'),
            'subtitle' => __('skus.label_print_subtitle'),
        ]);
    }

    private function validateChoices(SkuLabelContentResolver $resolver): void
    {
        validator([
            'layoutKey' => $this->layoutKey,
            'entries' => $this->entries,
            'skipCells' => $this->skipCells,
        ], [
            'layoutKey' => ['required', 'string', 'in:'.implode(',', array_keys($this->layouts()))],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.sku_id' => ['required', 'integer'],
            'entries.*.content' => ['required', 'string'],
            'entries.*.value' => ['required', 'string'],
            'entries.*.name' => ['nullable', 'string', 'max:255'],
            'entries.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'skipCells' => ['array'],
            'skipCells.*' => ['integer', 'min:0', 'max:'.($this->safeCurrentLayout()->cellsPerPage() - 1)],
        ])->after(function ($validator) use ($resolver): void {
            foreach ($this->entries as $index => $entry) {
                $sku = $this->skuQuery()->find((int) ($entry['sku_id'] ?? 0));

                if (! $sku) {
                    $validator->errors()->add("entries.{$index}.sku_id", __('validation.exists'));

                    continue;
                }

                $content = (string) ($entry['content'] ?? '');

                if (! array_key_exists($content, $resolver->options($sku))) {
                    $validator->errors()->add("entries.{$index}.content", __('skus.label_value_missing'));
                }
            }
        })->validate();
    }

    private function skuQuery()
    {
        return Sku::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with('stockItem');
    }

    /**
     * @return list<int>
     */
    private function seedSkuIds(?Sku $sku): array
    {
        $requestedIds = $sku
            ? [(int) $sku->id]
            : $this->requestedSkuIds();

        if ($requestedIds === []) {
            return [];
        }

        $allowedIds = $this->skuQuery()
            ->whereIn('id', $requestedIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return collect($requestedIds)
            ->filter(fn (int $id): bool => in_array($id, $allowedIds, true))
            ->values()
            ->all();
    }

    /**
     * @return array{sku_id: int, content: string, value: string, name: string, qty: int}
     */
    private function defaultEntry(int $skuId): array
    {
        $sku = $this->skuQuery()->find($skuId);
        $content = $sku && filled($sku->platform_label_code) ? 'fnsku' : 'sku';
        $value = $sku ? app(SkuLabelContentResolver::class)->resolveValue($sku, $content) : null;

        return [
            'sku_id' => $skuId,
            'content' => $content,
            'value' => $value ?? '',
            'name' => $sku?->displayName() ?? '',
            'qty' => 1,
        ];
    }

    /**
     * @return list<int>
     */
    private function requestedSkuIds(): array
    {
        $value = request()->query('sku_ids', '');
        $ids = is_array($value) ? $value : explode(',', (string) $value);

        return collect($ids)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function authorizeInternalUser(): void
    {
        if (Auth::user()?->user_type !== 'internal') {
            abort(403);
        }
    }

    /**
     * @return list<int>
     */
    private function allowedTenantIds(): array
    {
        if (Auth::user()?->user_type === 'internal') {
            return Tenant::query()->pluck('id')->all();
        }

        return Auth::user()?->activeTenantIds() ?? [];
    }
}
