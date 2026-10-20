<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Component
{
    use Toast;
    use WithPagination;

    public array $expanded = [];

    public string $search = '';

    public string $filterStatus = 'active';

    public bool $formOpen = false;

    public bool $unitModal = false;

    public int $perPage = 20;

    public array $sortBy = ['column' => 'n_code', 'direction' => 'asc'];

    public bool $showHelpModal = false;

    public $n_code;

    public $password;

    public $person_search = '';

    public $editing_user_id = null;

    public array $allRoles = [];

    public array $role_ids = [];

    public array $allPermissions = [];

    public array $user_permissions = [];

    public array $unit_ids = [];

    public function mount(): void
    {
        $this->authorize('manage_users');

        $this->allRoles = Role::all(['id', 'name', 'label'])->toArray();
        $this->allPermissions = Permission::all(['id', 'name', 'label'])->toArray();

        // Issue #849: the unit picker's WRITE-target rows moved to the
        // `allUnits` / `allUnitsTree` computed properties below — they are
        // render-time data, not snapshot state (#1040). Their scoping contract
        // is unchanged: only units inside the actor's reachable set, and the
        // whereIn is unconditional — an empty scope renders an empty picker
        // (0 = 1), never the whole organization (AGENTS.md fail-closed rule).
    }

    public function clear(): void
    {
        $this->reset(['search', 'filterStatus']);
        $this->filterStatus = 'active';
        $this->success('فیلترها پاک شدند.', position: 'toast-bottom');
    }

    /**
     * The scoped unit list both picker computeds below build from — one
     * query per RENDER (not one per picker): rendered shares today resolve
     * through all-props for the chips AND all-units-tree for the modals
     * within the same rebuild pass. Memoized into a PRIVATE property, so it
     * behaves like a computed's per-request cache without ever touching the
     * snapshot (Livewire 4 dehydrates public properties only — #1040 keeps
     * the model list out of the payload). Re-evaluated fresh the moment the
     * first picker build touches it each request, so it always reads this
     * request's `accessibleUnitIds()`.
     *
     * @var \Illuminate\Database\Eloquent\Collection<int, Unit>|null
     */
    private ?\Illuminate\Database\Eloquent\Collection $scopedUnitsCache = null;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Unit>
     */
    private function scopedAccessibleUnits(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->scopedUnitsCache === null) {
            $accessibleIds = app(AccessService::class)->accessibleUnitIds();

            $this->scopedUnitsCache = Unit::query()
                ->with('unitType')
                // #849: this picker is the WRITE target list, so it may only
                // offer units inside the actor's reachable set. The whereIn is
                // unconditional — an empty scope renders an empty picker (0 = 1),
                // never the whole organization (AGENTS.md fail-closed rule).
                ->whereIn('id', $accessibleIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        return $this->scopedUnitsCache;
    }

    /**
     * #1040: the flat options the unit picker's chips read — compute key
     * `['id', 'name']`, shape unchanged from the former public array.
     *
     * @return array<int, array{id: int, name: string}>
     */
    #[\Livewire\Attributes\Computed]
    public function allUnits(): array
    {
        $units = $this->scopedAccessibleUnits();

        return $units->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();
    }

    /**
     * #1040: the tree rows the unit-tree-picker modal reads — keys
     * `['id', 'name', 'parent_id', 'unit_type_name']`, shape unchanged from
     * the former public array. As a computed this never enters the
     * snapshot, so the org tree stops travelling with every roundtrip.
     *
     * @return array<int, array{id: int, name: string, parent_id: int|null, unit_type_name: string|null}>
     */
    #[\Livewire\Attributes\Computed]
    public function allUnitsTree(): array
    {
        $units = $this->scopedAccessibleUnits();

        return $units->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'parent_id' => $u->parent_id,
            'unit_type_name' => $u->unitType?->name,
        ])->all();
    }

    /**
     * The acting user, typed as the Eloquent model so static analysis can
     * resolve the permission and role checks below.
     */
    private function actor(): User
    {
        $actor = \Illuminate\Support\Facades\Auth::user();

        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

    /**
     * The acting user's id, resolved through the typed actor() so static
     * analysis does not flag it on the auth factory contract.
     */
    private function actorId(): int
    {
        return $this->actor()->id;
    }

    /**
     * Issue #849: EVERY unit id handed to a mutation must sit inside the
     * actor's reachable set. `accessibleUnitIds()` is legitimately `[]`, and
     * `[]` means "in scope of nothing", never "unrestricted" (AGENTS.md) —
     * a plain `in_array` against it therefore fails closed and there is no
     * `when($ids, …)` / `! empty($ids)` guard anywhere in this file.
     *
     * @param  array<int|string>  $unitIds
     */
    private function assertUnitIdsInScope(array $unitIds): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        foreach ($unitIds as $unitId) {
            if (! in_array((int) $unitId, $accessibleIds, true)) {
                abort(403, 'شما مجاز به دسترسی به این واحد سازمانی نیستید.');
            }
        }
    }

    /**
     * Issue #1028: the person an account binds to is part of the account's
     * inherited scope. `AccessService::accessibleUnitIds()` falls back to
     * `$user->person?->u_id` for an account with no `current_unit_id` and no
     * `user_units` rows, so binding to a foreign-unit person handed that
     * whole subtree over — #849 closed the `unit_ids` path, this closes the
     * `n_code` path. The `whereIn('u_id', …)` also fails closed when the
     * scope is `[]` (compiles to `0 = 1` — in scope of nothing, AGENTS.md).
     * `persons.u_id` is nullable since migration `2026_09_03_002219`, but
     * that is no escape hatch either: a NULL `u_id` matches no `IN` list in
     * SQL, so a person with no unit is refused too (fail-closed by `IN`
     * semantics, not by NOT NULL).
     *
     * Pattern reference: `hardware/index.blade.php` (HardwareImport-bound
     * form) applies the same exists+scope shape on persons.
     */
    private function personNCodeExistsRule(): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists('persons', 'n_code')->where(
            fn ($q) => $q->whereIn('u_id', app(AccessService::class)->accessibleUnitIds())
        );
    }

    /**
     * Issue #849: a target user is reachable only when its linked person's
     * unit OR one of its `user_units` rows falls inside the actor's scope.
     * The disjunction matters — `whereHas('person', …)` alone would hide
     * accounts with no linked person (admin accounts), which is exactly why
     * `users()` filters on the same two branches.
     *
     * `edit()` uses this so foreign state never lands in the public Livewire
     * properties; the mutating methods re-check it themselves, because
     * `editing_user_id` is public, client-settable state and a check inside
     * `edit()` alone is bypassable.
     */
    private function assertUserInScope(User $user): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $personUnitId = $user->person?->u_id;
        $inScope = $personUnitId !== null && in_array((int) $personUnitId, $accessibleIds, true);

        if (! $inScope) {
            $inScope = $user->units()->pluck('units.id')
                ->contains(fn ($unitId): bool => in_array((int) $unitId, $accessibleIds, true));
        }

        if (! $inScope) {
            abort(403, 'این کاربر خارج از محدوده واحد سازمانی شماست.');
        }
    }

    public function delete(User $user): void
    {
        $this->authorize('manage_users');

        if ($user->id === $this->actorId()) {
            abort(403, 'شما نمی‌توانید خودتان را غیرفعال کنید.');
        }

        // Issue #849: deactivating a user of another unit is cross-unit.
        // Checked before the admin guard so scope and role stay independent.
        $this->assertUserInScope($user);

        if ($user->hasRole('admin') && ! $this->actor()->hasRole('admin')) {
            abort(403, 'تنها مدیران می‌توانند کاربران مدیر را غیرفعال کنند.');
        }

        $user->delete();
        $this->warning("$user->name غیرفعال شد", 'غیرفعال شد!', position: 'toast-bottom');
    }

    public function restore($userId): void
    {
        $this->authorize('manage_users');
        $user = User::withTrashed()->findOrFail($userId);

        if ($user->id === $this->actorId()) {
            $this->error('شما نمی‌توانید خودتان را فعال کنید.', position: 'toast-bottom');

            return;
        }

        // Issue #849: re-activating a user of another unit is cross-unit.
        $this->assertUserInScope($user);

        if ($user->hasRole('admin') && ! $this->actor()->hasRole('admin')) {
            abort(403, 'تنها مدیران می‌توانند کاربران مدیر را فعال کنند.');
        }

        $user->restore();
        $this->success("$user->name فعال شد", 'کاربر برگشت!', position: 'toast-bottom');
    }

    public function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['n_code', 'password', 'person_search', 'editing_user_id', 'role_ids', 'user_permissions', 'unit_ids', 'formOpen', 'unitModal']);
    }

    public function openFormForCreate(): void
    {
        $this->authorize('manage_users');
        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit($userId): void
    {
        $this->authorize('manage_users');
        $this->resetValidation();
        $user = User::withTrashed()->findOrFail($userId);

        // Issue #849: `edit()` fills PUBLIC Livewire state (unit_ids,
        // user_permissions, n_code), so a foreign target is a read leak even
        // before any write. Mutations re-check regardless — see
        // assertUserInScope() and the direct `editing_user_id` path.
        $this->assertUserInScope($user);

        $this->editing_user_id = $user->id;
        $this->n_code = $user->n_code;
        $person = Person::where('n_code', $user->n_code)->first();
        $this->person_search = $person ? "{$person->f_name} {$person->l_name} ({$person->n_code})" : '';
        $this->password = null;
        $this->role_ids = $user->roles->pluck('id')->toArray();
        $this->user_permissions = $user->getDirectPermissions()->pluck('name')->map(fn ($n) => (string) $n)->toArray();
        $this->unit_ids = $user->units()->pluck('units.id')->map(fn ($id) => (int) $id)->toArray();
        $this->formOpen = true;
        $this->unitModal = false;
    }

    public function selectPerson($n_code): void
    {
        $person = Person::where('n_code', $n_code)->first();

        // Issue #849: `n_code` is the account key, and this method resolves
        // an arbitrary one from client state — the dropdown is already
        // scoped by getFilteredPersonsProperty(), so a direct call that lands
        // outside the actor's units is an out-of-scope read, not a lookup.
        if ($person && ! in_array((int) $person->u_id, app(AccessService::class)->accessibleUnitIds(), true)) {
            abort(403, 'این پرسنل خارج از محدوده واحد سازمانی شماست.');
        }

        $this->n_code = $n_code;
        if ($person) {
            $this->person_search = "{$person->f_name} {$person->l_name} ({$person->n_code})";
        }
    }

    public function createUser(): void
    {
        $this->authorize('manage_users');

        // Issue #849: every submitted unit must be inside the actor's own
        // scope — before validation, so the guard is the first thing an
        // out-of-scope write meets.
        $this->assertUnitIdsInScope($this->unit_ids);

        if (! empty($this->role_ids) || ! empty($this->user_permissions)) {
            $this->authorize('manage_roles');
        }

        $this->validate([
            'n_code' => ['required', $this->personNCodeExistsRule(), 'unique:users,n_code'],
            'password' => 'required|string|min:6',
            // Issue #849: aligned with updateUser() — `nullable|array` let a
            // manage_users-only holder plant a role-less account in any unit;
            // the two paths must not be able to diverge again.
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
            'user_permissions' => 'nullable|array',
            'user_permissions.*' => 'exists:permissions,name',
        ], [
            'n_code.unique' => 'این کد ملی قبلاً ثبت شده است.',
            'n_code.required' => 'کد ملی الزامی است.',
            'n_code.exists' => 'این کد ملی در سیستم وجود ندارد یا خارج از محدودهٔ واحدهای مجاز شماست.',
            'password.required' => 'رمز عبور الزامی است.',
            'password.min' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.',
            'role_ids.required' => 'حداقل یک نقش باید انتخاب شود.',
        ]);

        try {
            User::create([
                'n_code' => $this->n_code,
                'password' => bcrypt($this->password),
            ]);

            $user = User::where('n_code', $this->n_code)->first();
            $user->roles()->sync($this->role_ids ?? []);
            $user->syncPermissions($this->user_permissions ?? []);
            $user->units()->sync($this->unit_ids ?? []);
            app(AccessService::class)->clearCache($user);

            $this->resetForm();
            $this->success('کاربر با موفقیت ایجاد شد.');
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->error($error, position: 'toast-bottom');
            }
        }
    }

    public function updateUser(): void
    {
        $this->authorize('manage_users');

        // Issue #849: the scope guard lives HERE, not only in edit() —
        // `editing_user_id` is public, client-settable Livewire state with no
        // #[Locked], so a direct state write reaches this method without the
        // form ever being opened. Both the loaded target and every submitted
        // unit_id are checked (same rule as kargozini.person validates the
        // stored AND the submitted u_id).
        $target = User::withTrashed()->find($this->editing_user_id);
        if ($target instanceof User) {
            $this->assertUserInScope($target);

            // `units()->sync()` below is a full REPLACE, so the target's
            // CURRENT units must be in scope too — otherwise a straddling
            // user could be re-parented out of a unit the actor never had.
            $this->assertUnitIdsInScope($target->units()->pluck('units.id')->all());
        }
        $this->assertUnitIdsInScope($this->unit_ids);

        if (! empty($this->role_ids) || ! empty($this->user_permissions)) {
            $this->authorize('manage_roles');
        }

        $this->validate([
            'n_code' => ['required', $this->personNCodeExistsRule(), 'unique:users,n_code,'.$this->editing_user_id],
            'password' => 'nullable|string|min:6',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
            'user_permissions' => 'nullable|array',
            'user_permissions.*' => 'exists:permissions,name',
        ], [
            'n_code.unique' => 'این کد ملی قبلاً ثبت شده است.',
            'n_code.required' => 'کد ملی الزامی است.',
            'n_code.exists' => 'این کد ملی در سیستم وجود ندارد یا خارج از محدودهٔ واحدهای مجاز شماست.',
            'password.min' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.',
            'role_ids.required' => 'حداقل یک نقش باید انتخاب شود.',
        ]);

        try {
            $user = User::withTrashed()->findOrFail($this->editing_user_id);

            if ($user->hasRole('admin') && ! $this->actor()->hasRole('admin')) {
                abort(403, 'تنها مدیران می‌توانند کاربران مدیر را ویرایش کنند.');
            }

            $data = ['n_code' => $this->n_code];

            $passwordChanged = (bool) $this->password;

            if ($this->password) {
                $data['password'] = bcrypt($this->password);
            }

            $user->update($data);

            // Issue #1030: an admin password reset must revoke the target's
            // Sanctum tokens, exactly like the self-service path
            // (`auth/changepassword`) already does. Guarded on an
            // actually-changed password: a plain profile/role edit must not log
            // the user's devices out. Every token dies (including
            // `map-dashboard`) so both password-change paths stay in lockstep —
            // see `docs/agents/api-auth.md`.
            if ($passwordChanged) {
                $user->tokens()->delete();
            }

            $user->roles()->sync($this->role_ids ?? []);
            $user->syncPermissions($this->user_permissions ?? []);
            $user->units()->sync($this->unit_ids ?? []);
            app(AccessService::class)->clearCache($user);

            $this->resetForm();
            $this->success($passwordChanged
                ? 'کاربر با موفقیت ویرایش شد. با تغییر رمز، نشست‌های فعال کاربر بسته شد.'
                : 'کاربر با موفقیت ویرایش شد.');
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->error($error, position: 'toast-bottom');
            }
        }
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden xl:table-cell'],
            ['key' => 'name', 'label' => 'نام', 'class' => 'w-40', 'sortable' => false],
            ['key' => 'n_code', 'label' => 'کد ملی', 'class' => 'w-30 hidden sm:table-cell'],
            ['key' => 'unit_name', 'label' => 'واحد اصلی', 'class' => 'w-40 hidden sm:table-cell'],
            ['key' => 'roles_name', 'label' => 'نقش‌ها', 'class' => 'w-70 hidden sm:table-cell'],
            ['key' => 'status', 'label' => 'وضعیت', 'class' => 'w-20'],
        ];
    }

    public function users(): LengthAwarePaginator
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $query = User::query()
            // Issue #1014: eager-load every relation the table/SLOT reads.
            // MaryUI renders the expansion slot for EVERY row (CSS-hidden),
            // and its getAllPermissions() + the unit_name accessor each paid
            // 2 lazy queries per row (~80 queries at perPage=20). The FULL
            // chain matters — `person:id,n_code` would make the accessors
            // lazy-load again. Direct `permissions` is loaded too: Spatie's
            // getAllPermissions() reads it before falling back to roles.
            // The two withAggregate('person', …) calls were redundant: the
            // aliases had zero consumers (grep: person_f_name/person_l_name).
            // Issue #849 scope below is UNTOUCHED.
            ->with([
                'roles.permissions',
                'permissions',
                'person.unit',
            ])
            // Issue #849: scope the directory. A DISJUNCTION, not the
            // activity-log predicate verbatim — `whereHas('person', …)` alone
            // would hide accounts with no linked person or a null u_id (admin
            // accounts). Unconditional whereIn on both branches: an empty
            // scope compiles to 0 = 1 on each side, so [] shows nobody.
            ->where(function (Builder $q) use ($accessibleIds) {
                $q->whereHas('person', function (Builder $personQuery) use ($accessibleIds) {
                    $personQuery->whereIn('u_id', $accessibleIds);
                })->orWhereHas('units', function (Builder $unitQuery) use ($accessibleIds) {
                    $unitQuery->whereIn('units.id', $accessibleIds);
                });
            })
            ->when($this->search, function (Builder $q) {
                // Fold both the column (CONCAT) and the term for Persian char equivalence.
                $term = \App\Traits\PersianNormalizer::foldedTerm($this->search);
                $foldedConcat = \App\Traits\PersianNormalizer::foldSeparatorsSql("CONCAT(f_name, ' ', l_name)");

                $q->whereHas('person', function ($query) use ($term, $foldedConcat) {
                    $query->whereRaw("{$foldedConcat} LIKE ?", ["%{$term}%"])
                        ->orWhere('n_code', 'like', "%{$term}%");
                });
            })
            ->whereNot('id', $this->actorId());

        if ($this->filterStatus === 'active') {
            $query->whereNull('deleted_at');
        } elseif ($this->filterStatus === 'inactive') {
            $query->onlyTrashed();
        } else {
            $query->withTrashed();
        }

        // #914: the column is pinned, but `direction` is client-settable and
        // was never validated — an unknown value raised InvalidArgumentException
        // and a missing key raised ErrorException under error_reporting(-1).
        $direction = ($this->sortBy['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy('n_code', $direction)
            ->paginate($this->perPage);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getFilteredPersonsProperty(): array
    {
        // Only search when the form is open AND at least 2 characters typed.
        // Without this guard every Livewire update loaded every Person row
        // into the view, causing slowness, missing renders, and Edit/New
        // failing to open (no errors in console — just silent timeouts).
        if (! $this->formOpen || mb_strlen($this->person_search) < 2) {
            return [];
        }

        $term = \App\Traits\PersianNormalizer::foldedTerm($this->person_search);
        $foldedConcat = \App\Traits\PersianNormalizer::foldSeparatorsSql("CONCAT(f_name, ' ', l_name)");
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        return Person::query()
            // Issue #849: the only component-side path that hands a full name
            // + national code of an arbitrary person to a manage_users holder.
            // Same predicate as `->accessible('u_id')`, written out because a
            // local scope call is not resolvable without larastan: an
            // unconditional whereIn, so an empty scope is 0 = 1 (never "no
            // filter"), and it sits in its own AND-group so the LIKE/OR below
            // cannot bypass it.
            ->where(function ($query) use ($accessibleIds) {
                $query->whereIn('u_id', $accessibleIds);
            })
            ->where(function ($query) use ($term, $foldedConcat) {
                $query->whereRaw("{$foldedConcat} LIKE ?", ["%{$term}%"])
                    ->orWhere('n_code', 'like', "%{$term}%");
            })
            ->limit(20)
            ->get()
            ->map(fn ($person) => [
                'value' => $person->n_code,
                'label' => "{$person->f_name} {$person->l_name} ({$person->n_code})",
            ])
            ->toArray();
    }

    public function with(): array
    {
        // `$this->allUnits()` is the computed property, not public state — the
        // chips read from the per-request list.
        $selectedUnitNames = collect($this->allUnits())
            ->whereIn('id', $this->unit_ids ?? [])
            ->pluck('name')
            ->values()
            ->all();

        return [
            'users' => $this->users(),
            'headers' => $this->headers(),
            'persons' => $this->getFilteredPersonsProperty(),
            'selectedUnitNames' => $selectedUnitNames,
        ];
    }
}; ?>

<div>
    <x-header title="کاربران" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="users" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4 flex-wrap">
            <x-ui.icon-button name="کاربر جدید" label="کاربر جدید" class="btn-success" wire:click="openFormForCreate" responsive icon="o-plus"/>
            <div class="flex-1 min-w-[12rem]">
                <x-input
                    placeholder="جستجو..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
            <div>
                <select wire:model.live="filterStatus" class="select select-bordered w-40">
                    <option value="all">همه</option>
                    <option value="active">فعال</option>
                    <option value="inactive">غیرفعال</option>
                </select>
            </div>
        </div>

        @if($formOpen)
            <div class="mb-6 p-4 bg-base-200 rounded-xl border border-base-300">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-sm">
                        {{ $editing_user_id ? 'ویرایش کاربر' : 'ثبت کاربر جدید' }}
                    </h3>
                    <x-ui.icon-button name="پاک کردن فرم" icon="o-x-mark" class="btn-ghost btn-sm" wire:click="resetForm" />
                </div>

                <x-form wire:submit.prevent="{{ $editing_user_id ? 'updateUser' : 'createUser' }}"
                        class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="relative">
                        <x-input wire:model.live.debounce.500ms="person_search" type="text" class="input input-bordered w-full" label="کد ملی"
                                 placeholder="جستجوی نام یا کد ملی"/>
                        @error('n_code') <span class="text-error text-sm">{{ $message }}</span> @enderror
                        @if($person_search)
                            <div class="max-h-40 overflow-auto border border-base-300 rounded-lg mt-1 bg-base-100">
                                @forelse($persons as $person)
                                    <div wire:click="selectPerson('{{ $person['value'] }}')"
                                         class="p-2 hover:bg-base-200 cursor-pointer text-sm">
                                        {{ $person['label'] }}
                                    </div>
                                @empty
                                    <div class="p-2 text-sm text-base-content/50">موردی یافت نشد</div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                    <div>
                        <x-input wire:model="password" label="رمز عبور" type="password"
                                 :placeholder="$editing_user_id ? 'در صورت نیاز وارد کنید' : 'رمز عبور'"
                                 :required="!$editing_user_id" rounded/>
                        @error('password') <span class="text-error text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-choices-offline
                            label="نقش‌ها"
                            wire:model="role_ids"
                            :options="$allRoles"
                            option-label="label"
                            option-value="id"
                            placeholder="انتخاب نقش..."
                            clearable
                            searchable
                        />
                        @error('role_ids') <span class="text-error text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-choices-offline
                            label="دسترسی‌های مستقیم"
                            wire:model="user_permissions"
                            :options="$allPermissions"
                            option-label="label"
                            option-value="name"
                            placeholder="جستجو در دسترسی‌ها..."
                            clearable
                            searchable
                        />
                        @error('user_permissions') <span class="text-error text-sm">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label class="text-sm font-medium block mb-1">واحدها</label>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="flex-1 min-w-[12rem] input input-bordered flex flex-wrap items-center gap-1 min-h-[2.75rem] py-1">
                                @forelse($selectedUnitNames as $unitName)
                                    <span class="badge badge-primary">{{ $unitName }}</span>
                                @empty
                                    <span class="text-base-content/40 text-sm">واحدی انتخاب نشده</span>
                                @endforelse
                            </div>
                            <x-button
                                type="button"
                                label="انتخاب واحد"
                                icon="o-building-office-2"
                                class="btn-outline btn-sm"
                                wire:click="$set('unitModal', true)"
                            />
                        </div>
                    </div>

                    <div class="sm:col-span-2 flex justify-end gap-2">
                        <x-button type="submit" label="ذخیره" icon="o-check" class="btn-primary" spinner />
                        <x-button type="button" label="لغو" wire:click="resetForm" icon="o-x-mark" class="btn-ghost" />
                    </div>
                </x-form>
            </div>
        @endif

        <x-table :headers="$headers" :rows="$users" :sort-by="$sortBy" wire:model="expanded" expandable with-pagination per-page="perPage" :per-page-values="[10, 20, 50, 100]">

            @scope('cell_status', $user)
                <x-badge
                    :value="$user->trashed() ? 'غیرفعال' : 'فعال'"
                    :class="$user->trashed() ? 'badge-error' : 'badge-success'"
                    rounded
                />
            @endscope

            @scope('cell_roles_name', $user)
                @forelse($user->roles as $role)
                    <x-badge :value="$role->label ?? $role->name" class="badge-primary" rounded />
                @empty
                    <span class="text-muted text-sm">—</span>
                @endforelse
            @endscope

            @scope('actions', $user)
                <div class="flex w-1/12">
                    <x-ui.icon-button name="ویرایش کاربر" icon="o-pencil"
                              wire:click="edit({{ $user->id }})"
                              class="btn-ghost btn-sm text-primary" />
                    @if($user->trashed())
                        <x-ui.icon-button name="فعال‌سازی کاربر" icon="o-arrow-path"
                                  wire:click="restore({{ $user->id }})"
                                  wire:confirm="آیا مطمئن هستید که می‌خواهید این کاربر را فعال کنید؟"
                                  spinner
                                  class="btn-ghost btn-sm text-success" />
                    @else
                        <x-ui.icon-button name="غیرفعال کردن کاربر" icon="o-trash"
                                  wire:click="delete({{ $user->id }})"
                                  wire:confirm="آیا مطمئن هستید که می‌خواهید این کاربر را غیرفعال کنید؟"
                                  spinner
                                  class="btn-ghost btn-sm text-error" />
                    @endif
                </div>
            @endscope

            @scope('expansion', $user)
                <div class="bg-base-200 p-6">
                    <div class="mb-3">
                        <span class="font-bold">دسترسی‌ها برای</span>
                        <span class="font-medium">{{ $user->name }}</span>
                    </div>
                    @php
                        $permissions = $user->getAllPermissions();
                    @endphp
                    @if($permissions->isEmpty())
                        <div class="text-sm text-muted">هیچ دسترسی ثبت نشده است.</div>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach($permissions as $perm)
                                <x-badge :value="$perm->label ?? $perm->name" class="badge-info" rounded/>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endscope
        </x-table>
    </x-card>

    <x-modal wire:model="unitModal" title="انتخاب واحدها" persistent separator>
        {{-- #1171: render-on-open. The picker builds the whole org tree in PHP
             (≈494 KB of HTML on the dev database) and serialises every unit
             into inline JSON, so rendering it while the modal is closed wastes
             that cost on every keystroke and every page change. Opening the
             modal is already a round trip (`$set('unitModal', true)`), so the
             tree is built exactly when it becomes visible — no extra request.
             The scope contract (#849/#1040) is untouched: `@if` changes WHEN the
             tree renders, not WHICH units it contains. --}}
        @if ($unitModal)
            @include('livewire.partials.unit-tree-picker', [
                'units' => $this->allUnitsTree(),
                'model' => 'unit_ids',
                'multiple' => true,
                'alwaysOpen' => true,
                'label' => 'واحدهای سازمانی',
            ])
        @endif
        <x-slot:actions>
            <x-button label="تأیید" icon="o-check" class="btn-primary" wire:click="$set('unitModal', false)" />
            <x-button label="بستن" icon="o-x-mark" class="btn-ghost" wire:click="$set('unitModal', false)" />
        </x-slot:actions>
    </x-modal>
</div>