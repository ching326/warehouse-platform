<?php

namespace App\Livewire;

use App\Livewire\Concerns\HandlesPrivateMediaAssets;
use App\Models\Issue;
use App\Models\IssueLine;
use App\Models\MediaAsset;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class IssueShow extends Component
{
    use HandlesPrivateMediaAssets;
    use WithFileUploads;

    public int $issueId = 0;

    public string $status = '';

    public string $note = '';

    public array $lineDrafts = [];

    public ?TemporaryUploadedFile $photo = null;

    public string $photoType = 'damage';

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

    public function uploadPhoto(): void
    {
        $case = $this->issueQuery()->findOrFail($this->issueId);

        $this->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photoType' => ['required', Rule::in(['damage', 'other'])],
        ]);

        $this->createPrivateMediaAsset(
            $case,
            $this->photo,
            MediaAsset::MODEL_TYPE_ISSUE,
            $this->photoType,
            'media/private/tenant-'.$case->tenant_id.'/issues/'.$case->id,
            'issue',
        );

        $this->resetPhotoForm();
        session()->flash('status', __('media.image_uploaded'));
    }

    public function deletePhoto(int $mediaAssetId): void
    {
        $case = $this->issueQuery()->with('mediaAssets')->findOrFail($this->issueId);
        $asset = $case->mediaAssets->firstWhere('id', $mediaAssetId);

        if (! $asset) {
            abort(404);
        }

        $this->deletePrivateMediaAsset($asset, $case, 'issue');
        session()->flash('status', __('media.image_deleted'));
    }

    public function render()
    {
        $case = $this->issueQuery()
            ->with([
                'tenant:id,code,name',
                'salesOrder:id,platform_order_id',
                'outboundOrder:id,ref',
                'createdBy:id,name',
                'updatedBy:id,name',
                'lines.sku:id,sku,name',
                'lines.stockItem:id,code,name',
                'returnOrders:id,issue_id,return_no,status,tracking_no',
                'mediaAssets:id,tenant_id,model_type,model_id,type,disk,path,file_name,mime_type,width,height,sort_order',
            ])
            ->findOrFail($this->issueId);

        return view('livewire.issue-show', [
            'case' => $case,
            'statuses' => Issue::statusOptions(),
            'conditions' => IssueLine::conditionOptions(),
            'actions' => IssueLine::actionOptions(),
            'photoTypes' => $this->photoTypeOptions(),
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

        return $this->allowedTenantIdsCache = $user->activeTenantIds();
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function resetPhotoForm(): void
    {
        $this->photo = null;
        $this->photoType = 'damage';
    }

    private function photoTypeOptions(): array
    {
        return [
            'damage' => __('media.type_damage'),
            'other' => __('media.type_other'),
        ];
    }
}
