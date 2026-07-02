<?php

namespace App\Livewire;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Users\TenantMembershipService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class UserCreate extends Component
{
    public string $name = '';

    public string $email = '';

    public string $userType = User::TYPE_TENANT;

    public string $internalRole = User::ROLE_WAREHOUSE_STAFF;

    public bool $isActive = true;

    public string $tenantId = '';

    public string $tenantRole = TenantUser::ROLE_STAFF;

    public ?string $tempPassword = null;

    public ?int $createdUserId = null;

    public function mount(): void
    {
        if (! Auth::user()?->canManageUsers()) {
            abort(403);
        }
    }

    public function updatedUserType(): void
    {
        if ($this->userType === User::TYPE_INTERNAL) {
            $this->tenantId = '';
            $this->tenantRole = TenantUser::ROLE_STAFF;
        } else {
            $this->internalRole = User::ROLE_WAREHOUSE_STAFF;
        }
    }

    public function save(TenantMembershipService $memberships): void
    {
        $data = $this->validate($this->rules());
        $password = Str::random(16);
        $actor = Auth::user();

        DB::transaction(function () use ($data, $password, $actor, $memberships): void {
            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'password' => $password,
                'user_type' => $data['userType'],
                'role' => $data['userType'] === User::TYPE_INTERNAL ? $data['internalRole'] : null,
                'is_active' => (bool) $data['isActive'],
            ]);

            if ($data['userType'] === User::TYPE_TENANT) {
                $memberships->addMembership($actor, (int) $data['tenantId'], $user, $data['tenantRole']);
            }

            $this->createdUserId = $user->id;
        });

        $this->tempPassword = $password;
        session()->flash('status', __('users.user_created'));
    }

    public function render()
    {
        return view('livewire.user-create', [
            'userTypes' => $this->userTypeOptions(),
            'internalRoles' => $this->internalRoleOptions(),
            'tenantRoles' => $this->tenantRoleOptions(),
            'tenants' => Tenant::query()->orderBy('code')->get(['id', 'code', 'name']),
        ])->layout('inventory', [
            'title' => __('users.create_title'),
            'subtitle' => __('users.create_subtitle'),
        ]);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'userType' => ['required', Rule::in([User::TYPE_INTERNAL, User::TYPE_TENANT])],
            'internalRole' => [
                Rule::requiredIf($this->userType === User::TYPE_INTERNAL),
                Rule::in([User::ROLE_INTERNAL_ADMIN, User::ROLE_WAREHOUSE_STAFF]),
            ],
            'isActive' => ['boolean'],
            'tenantId' => [Rule::requiredIf($this->userType === User::TYPE_TENANT), 'nullable', Rule::exists('tenants', 'id')->where('status', 'active')],
            'tenantRole' => [Rule::requiredIf($this->userType === User::TYPE_TENANT), Rule::in(TenantUser::ROLES)],
        ];
    }

    private function userTypeOptions(): array
    {
        return [
            User::TYPE_INTERNAL => __('users.type_internal'),
            User::TYPE_TENANT => __('users.type_tenant'),
        ];
    }

    private function internalRoleOptions(): array
    {
        return [
            User::ROLE_INTERNAL_ADMIN => __('users.role_internal_admin'),
            User::ROLE_WAREHOUSE_STAFF => __('users.role_warehouse_staff'),
        ];
    }

    private function tenantRoleOptions(): array
    {
        return [
            TenantUser::ROLE_ADMIN => __('users.role_tenant_admin'),
            TenantUser::ROLE_STAFF => __('users.role_tenant_staff'),
        ];
    }
}
