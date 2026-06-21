<?php

namespace App\Livewire;

use App\Models\Issue;
use App\Models\IssueLine;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class IssueShow extends Component
{
    public int $issueId = 0;

    public string $status = '';

    public string $note = '';

    public array $lineDrafts = [];

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(Issue $issue): void
    {
        if (! in_array($issue->tenant_id, $this->allowedTenantIds(), true)) {
            abort(403);
        }

        $this->issueId = $issue->id;
        $this->fillDrafts($issue->load('lines'));
    }

    public function saveIssue(): void
    {
        $case = $this->issueQuery()->findOrFail($this->issueId);

        if ($case->isClosed()) {
            session()->flash('error', __('issues.issue_read_only'));

            return;
        }

        validator([
            'status' => $this->status,
            'note' => $this->note,
        ], [
            'status' => ['required', Rule::in(array_keys(Issue::statusOptions()))],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $case->update([
            'status' => $this->status,
            'note' => $this->nullableString($this->note),
            'resolved_at' => in_array($this->status, [Issue::STATUS_RESOLVED, Issue::STATUS_CLOSED], true) ? now() : null,
            'updated_by_user_id' => Auth::id(),
        ]);

        session()->flash('status', __('issues.issue_updated'));
    }

    public function saveLines(): void
    {
        $case = $this->issueQuery()->with('lines')->findOrFail($this->issueId);

        if ($case->isClosed()) {
            session()->flash('error', __('issues.issue_read_only'));

            return;
        }

        foreach ($case->lines as $line) {
            $draft = $this->lineDrafts[$line->id] ?? null;

            if (! $draft) {
                continue;
            }

            validator($draft, [
                'condition' => ['required', Rule::in(array_keys(IssueLine::conditionOptions()))],
                'action' => ['required', Rule::in(array_keys(IssueLine::actionOptions()))],
                'note' => ['nullable', 'string', 'max:1000'],
            ])->validate();

            $line->update([
                'condition' => $draft['condition'],
                'action' => $draft['action'],
                'note' => $this->nullableString($draft['note'] ?? ''),
            ]);
        }

        $case->update(['updated_by_user_id' => Auth::id()]);
        session()->flash('status', __('issues.lines_updated'));
    }

    public function render()
    {
        $case = $this->issueQuery()
            ->with([
                'tenant:id,code,name',
                'salesOrder:id,platform_order_id',
                'fulfillmentGroup:id,reference_no',
                'outboundOrder:id,ref',
                'createdBy:id,name',
                'updatedBy:id,name',
                'lines.sku:id,sku,name',
                'lines.stockItem:id,code,name',
                'returnOrders:id,issue_id,return_no,status,tracking_no',
            ])
            ->findOrFail($this->issueId);

        return view('livewire.issue-show', [
            'case' => $case,
            'statuses' => Issue::statusOptions(),
            'conditions' => IssueLine::conditionOptions(),
            'actions' => IssueLine::actionOptions(),
        ])->layout('inventory', [
            'title' => $case->issue_no,
            'subtitle' => __('issues.detail_page_subtitle'),
        ]);
    }

    private function fillDrafts(Issue $case): void
    {
        $this->status = $case->status;
        $this->note = (string) $case->note;
        $this->lineDrafts = $case->lines
            ->mapWithKeys(fn (IssueLine $line) => [
                $line->id => [
                    'condition' => $line->condition,
                    'action' => $line->action,
                    'note' => (string) $line->note,
                ],
            ])
            ->all();
    }

    private function issueQuery()
    {
        return Issue::query()->whereIn('tenant_id', $this->allowedTenantIds());
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

