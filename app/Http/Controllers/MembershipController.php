<?php

namespace App\Http\Controllers;

use App\Actions\CreateMembershipUserAction;
use App\Actions\UpdateMembershipUserAction;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Http\Requests\StoreMembershipRequest;
use App\Http\Requests\UpdateMembershipRequest;
use App\Models\Membership;
use App\Services\MembershipPermissionService;
use App\Services\MembershipQueryService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MembershipController extends Controller
{
    public function index(MembershipQueryService $memberships): View
    {
        Gate::authorize('viewAny', Membership::class);
        $memberships = $memberships->forCompany($this->company());
        $roles = MembershipRole::cases();

        return view('memberships.index', compact('memberships', 'roles'));
    }

    public function create(MembershipPermissionService $permissions): View
    {
        Gate::authorize('create', Membership::class);

        return view('memberships.form', $this->formData(null, $permissions));
    }

    public function store(StoreMembershipRequest $request, CreateMembershipUserAction $action): RedirectResponse
    {
        Gate::authorize('create', Membership::class);

        try {
            $action->execute($this->company(), $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['email' => $exception->getMessage()]);
        }

        return redirect()->route('memberships.index')->with('success', 'Usuario agregado a la empresa.');
    }

    public function edit(int $membership, MembershipPermissionService $permissions): View
    {
        $membership = $this->membership($membership);
        Gate::authorize('update', $membership);

        return view('memberships.form', $this->formData($membership->load(['user', 'permissionOverrides']), $permissions));
    }

    public function update(UpdateMembershipRequest $request, int $membership, UpdateMembershipUserAction $action): RedirectResponse
    {
        $membership = $this->membership($membership);
        Gate::authorize('update', $membership);

        try {
            $action->execute($membership, $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['membership' => $exception->getMessage()]);
        }

        return redirect()->route('memberships.index')->with('success', 'Usuario actualizado.');
    }

    private function membership(int $id): Membership
    {
        return Membership::query()->where('company_id', $this->company()->id)->findOrFail($id);
    }

    private function formData(?Membership $membership, MembershipPermissionService $permissions): array
    {
        $roles = MembershipRole::cases();
        $rolePermissions = collect($roles)->mapWithKeys(fn (MembershipRole $role): array => [
            $role->value => collect($role->permissions())->map->value->all(),
        ])->all();
        $permissionStates = $membership?->permissionOverrides
            ->mapWithKeys(fn ($override): array => [$override->permission->value => $override->allowed ? 'allow' : 'deny'])
            ->all() ?? [];
        $actorPermissions = collect(Permission::cases())
            ->filter(fn ($permission): bool => request()->user()->canForCompany($permission, $this->company()))
            ->map->value->all();

        return [
            'membership' => $membership,
            'roles' => $roles,
            'permissionGroups' => $permissions->groupedCatalog(),
            'rolePermissions' => $rolePermissions,
            'permissionStates' => $permissionStates,
            'actorPermissions' => $actorPermissions,
        ];
    }
}
