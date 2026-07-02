<?php

namespace App\Livewire;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class UserIndex extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'type', except: '')]
    public string $userType = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(as: 'status', except: 'active')]
    public string $status = 'active';

    public function mount(): void
    {
        if (! Auth::user()?->canManageUsers()) {
            abort(403);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedUserType(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $search = trim($this->search);

        $users = User::query()
            ->with(['tenantUsers.tenant:id,code,name'])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($this->userType !== '', fn ($query) => $query->where('user_type', $this->userType))
            ->when($this->role !== '', function ($query): void {
                if (in_array($this->role, [User::ROLE_INTERNAL_ADMIN, User::ROLE_WAREHOUSE_STAFF], true)) {
                    $query->where('role', $this->role);

                    return;
                }

                $query->whereHas('tenantUsers', fn ($tenantUser) => $tenantUser
                    ->where('status', TenantUser::STATUS_ACTIVE)
                    ->where('role', $this->role));
            })
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->simplePaginate(30);

        return view('livewire.user-index', [
            'users' => $users,
            'userTypes' => $this->userTypeOptions(),
            'roles' => $this->roleOptions(),
            'statuses' => $this->statusOptions(),
        ])->layout('inventory', [
            'title' => __('users.index_title'),
            'subtitle' => __('users.index_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function userTypeLabel(?string $type): string
    {
        return $this->userTypeOptions()[$type] ?? '-';
    }

    public function roleLabel(?string $role): string
    {
        return $this->roleOptions()[$role] ?? '-';
    }

    public function membershipChips(User $user): array
    {
        return $user->tenantUsers
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->map(fn (TenantUser $membership): string => $this->roleLabel($membership->role).' - '.($membership->tenant?->code ?? $membership->tenant_id))
            ->values()
            ->all();
    }

    private function userTypeOptions(): array
    {
        return [
            User::TYPE_INTERNAL => __('users.type_internal'),
            User::TYPE_TENANT => __('users.type_tenant'),
        ];
    }

    private function roleOptions(): array
    {
        return [
            User::ROLE_INTERNAL_ADMIN => __('users.role_internal_admin'),
            User::ROLE_WAREHOUSE_STAFF => __('users.role_warehouse_staff'),
            TenantUser::ROLE_ADMIN => __('users.role_tenant_admin'),
            TenantUser::ROLE_STAFF => __('users.role_tenant_staff'),
        ];
    }

    private function statusOptions(): array
    {
        return [
            'active' => __('users.status_active'),
            'all' => __('users.status_all'),
        ];
    }
}
