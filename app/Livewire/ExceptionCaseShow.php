<?php

namespace App\Livewire;

use App\Models\ExceptionCase;
use App\Models\ExceptionCaseLine;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ExceptionCaseShow extends Component
{
    public int $caseId = 0;

    public string $status = '';

    public string $note = '';

    public array $lineDrafts = [];

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(ExceptionCase $exceptionCase): void
    {
        if (! in_array($exceptionCase->tenant_id, $this->allowedTenantIds(), true)) {
            abort(403);
        }

        $this->caseId = $exceptionCase->id;
        $this->fillDrafts($exceptionCase->load('lines'));
    }

    public function saveCase(): void
    {
        $case = $this->caseQuery()->findOrFail($this->caseId);

        if ($case->isClosed()) {
            session()->flash('error', __('exception_cases.case_read_only'));

            return;
        }

        validator([
            'status' => $this->status,
            'note' => $this->note,
        ], [
            'status' => ['required', Rule::in(array_keys(ExceptionCase::statusOptions()))],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $case->update([
            'status' => $this->status,
            'note' => $this->nullableString($this->note),
            'resolved_at' => in_array($this->status, [ExceptionCase::STATUS_RESOLVED, ExceptionCase::STATUS_CLOSED], true) ? now() : null,
            'updated_by_user_id' => Auth::id(),
        ]);

        session()->flash('status', __('exception_cases.case_updated'));
    }

    public function saveLines(): void
    {
        $case = $this->caseQuery()->with('lines')->findOrFail($this->caseId);

        if ($case->isClosed()) {
            session()->flash('error', __('exception_cases.case_read_only'));

            return;
        }

        foreach ($case->lines as $line) {
            $draft = $this->lineDrafts[$line->id] ?? null;

            if (! $draft) {
                continue;
            }

            validator($draft, [
                'condition' => ['required', Rule::in(array_keys(ExceptionCaseLine::conditionOptions()))],
                'action' => ['required', Rule::in(array_keys(ExceptionCaseLine::actionOptions()))],
                'note' => ['nullable', 'string', 'max:1000'],
            ])->validate();

            $line->update([
                'condition' => $draft['condition'],
                'action' => $draft['action'],
                'note' => $this->nullableString($draft['note'] ?? ''),
            ]);
        }

        $case->update(['updated_by_user_id' => Auth::id()]);
        session()->flash('status', __('exception_cases.lines_updated'));
    }

    public function render()
    {
        $case = $this->caseQuery()
            ->with([
                'tenant:id,code,name',
                'salesOrder:id,platform_order_id',
                'fulfillmentGroup:id,reference_no',
                'outboundOrder:id,ref',
                'createdBy:id,name',
                'updatedBy:id,name',
                'lines.sku:id,sku,name',
                'lines.stockItem:id,code,name',
            ])
            ->findOrFail($this->caseId);

        return view('livewire.exception-case-show', [
            'case' => $case,
            'statuses' => ExceptionCase::statusOptions(),
            'conditions' => ExceptionCaseLine::conditionOptions(),
            'actions' => ExceptionCaseLine::actionOptions(),
        ])->layout('inventory', [
            'title' => $case->case_no,
            'subtitle' => __('exception_cases.detail_page_subtitle'),
        ]);
    }

    private function fillDrafts(ExceptionCase $case): void
    {
        $this->status = $case->status;
        $this->note = (string) $case->note;
        $this->lineDrafts = $case->lines
            ->mapWithKeys(fn (ExceptionCaseLine $line) => [
                $line->id => [
                    'condition' => $line->condition,
                    'action' => $line->action,
                    'note' => (string) $line->note,
                ],
            ])
            ->all();
    }

    private function caseQuery()
    {
        return ExceptionCase::query()->whereIn('tenant_id', $this->allowedTenantIds());
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        if ($this->isInternalUser()) {
            return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
        }

        $user = Auth::user();

        if (! $user) {
            return $this->allowedTenantIdsCache = [];
        }

        return $this->allowedTenantIdsCache = $user
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
