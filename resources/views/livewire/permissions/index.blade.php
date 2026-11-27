<?php

use App\Traits\ClampsPageSize;
use App\Traits\PersianNormalizer;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Spatie\Permission\Models\Permission;

return new class extends Component
{
    use ClampsPageSize;
    use PersianNormalizer;
    use Toast;
    use WithPagination;

    public string $name;

    public string $label;

    public ?int $editingId = null;

    public string $search = '';

    public int $perPage = 20;

    public bool $modal = false;

    public bool $showHelpModal = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // #914: `sortBy` is client-settable, so only real columns are sortable.
    private const SORTABLE_COLUMNS = ['id', 'label', 'name'];

    public function mount(): void
    {
        $this->authorize('manage_roles');
    }

    public function clear(): void
    {
        $this->reset();
        $this->info('فیلدها خالی شدند', position: 'toast-bottom');
    }

    /**
     * The acting user, typed as the Eloquent model so static analysis can
     * resolve the role checks in RoleGuard (same shape as users.index).
     */
    private function actor(): \App\Models\User
    {
        $actor = \Illuminate\Support\Facades\Auth::user();

        if (! $actor instanceof \App\Models\User) {
            abort(403);
        }

        return $actor;
    }

    public function delete(Permission $permission): void
    {
        $this->authorize('manage_roles');

        // Issue #1921: permission deletion is part of the #1607 delegation
        // ceiling — a non-admin may delete only a permission assigned to
        // nobody. BEFORE the try block: `abort(403)` is an HttpException and
        // the generic catch below would turn it into a toast (#892 shape).
        \App\Support\RoleGuard::assertCanDeletePermission($this->actor(), $permission);

        // Issue #1921 (#1663 shape): `manage_roles` gates this page and
        // /roles; no actor, admin included, may delete it.
        \App\Support\RoleGuard::assertCriticalPermissionSurvives($permission);

        try {
            // Issue #1823: capture the holders BEFORE the delete — the pivots
            // cascade away with the permission, and deleting it strips the
            // privilege from every holder, so their tokens must die with it.
            $holderIds = $this->permissionHolderIds($permission);

            \Illuminate\Support\Facades\DB::transaction(function () use ($permission, $holderIds): void {
                $permission->delete();
                $this->revokeTokensForUserIds($holderIds);
            });

            $this->warning("$permission->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // Issue #892: re-throw so a 403 is not reported to the user as the
            // generic "still used in another table" foreign-key toast.
            throw $e;
        } catch (\Exception $e) {
            $this->error('امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.', position: 'toast-bottom');
        }
    }

    public function createPermission(): void
    {
        $this->authorize('manage_roles');

        $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:permissions,name',
                'regex:/^[a-zA-Z0-9\s\-]+$/u',
            ],
            'label' => [
                'required',
                'string',
                'max:255',
                'unique:permissions,label',
                'regex:/^[\p{L}\p{N}\s\-]+$/u',
            ],
        ]);

        Permission::create(['name' => $this->name, 'label' => $this->label]);

        $this->success("$this->label ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->reset();
        $this->modal = false;
    }

    public function editPermission($id): void
    {
        $this->authorize('manage_roles');

        $permission = Permission::findOrFail($id);
        $this->editingId = $id;
        $this->name = $permission->name;
        $this->label = $permission->label;
    }

    public function updatePermission(): void
    {
        $this->authorize('manage_roles');

        $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:permissions,name,'.$this->editingId,
                'regex:/^[a-zA-Z0-9\s\-]+$/u',
            ],
            'label' => [
                'required',
                'string',
                'max:255',
                'unique:permissions,label,'.$this->editingId,
                'regex:/^[\p{L}\p{N}\s\-]+$/u',
            ],
        ]);

        // Issue #1921: the guard AND the lookup must sit BEFORE the try — an
        // `abort(403)` or a ModelNotFoundException inside it would be swallowed
        // by the generic catch into a "خطا در ویرایش" toast.
        $permission = Permission::findOrFail($this->editingId);

        // Issue #1921: a non-admin may rename only a permission it holds, and
        // only to a name it holds.
        \App\Support\RoleGuard::assertCanMutatePermission($this->actor(), $permission, $this->name);

        // Issue #1921 (#1663 shape): the route gate follows the name.
        \App\Support\RoleGuard::assertCriticalPermissionKeepsItsName($permission, $this->name);

        // Issue #1823: a NAME change rewrites the holders' derived token
        // abilities; a label-only edit must not log anyone out.
        $nameChanged = $this->name !== $permission->name;
        $holderIds = $nameChanged ? $this->permissionHolderIds($permission) : [];

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($permission, $holderIds): void {
                $permission->update(['name' => $this->name, 'label' => $this->label]);
                $this->revokeTokensForUserIds($holderIds);
            });

            $this->success("$this->name بروزرسانی شد ", 'با موفقیت', position: 'toast-bottom');
            $this->reset();
            $this->modal = false;
        } catch (\Exception $e) {
            $this->error('خطا در ویرایش', position: 'toast-bottom');
        }
    }

    /**
     * Issue #1823 — every user whose effective ability set contains this
     * permission: holders of a role that carries it, plus direct holders.
     *
     * @return array<int, int|string>
     */
    private function permissionHolderIds(Permission $permission): array
    {
        return \App\Models\User::query()
            ->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($permission): void {
                $query->whereHas('roles.permissions', fn (\Illuminate\Database\Eloquent\Builder $roles) => $roles->whereKey($permission->getKey()))
                    ->orWhereHas('permissions', fn (\Illuminate\Database\Eloquent\Builder $direct) => $direct->whereKey($permission->getKey()));
            })
            ->pluck('users.id')
            ->all();
    }

    /**
     * Issue #1823 — revoke the Sanctum tokens of the given user ids.
     *
     * Bulk delete (not `each(fn ($u) => $u->tokens()->delete())`) so a
     * permission with many holders costs one query. The morph-type filter
     * keeps the delete scoped to User tokens, never another tokenable model.
     *
     * @param  array<int, int|string>  $userIds
     */
    private function revokeTokensForUserIds(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        \Laravel\Sanctum\PersonalAccessToken::query()
            ->where('tokenable_type', (new \App\Models\User)->getMorphClass())
            ->whereIn('tokenable_id', $userIds)
            ->delete();
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'],
            ['key' => 'label', 'label' => 'عنوان', 'class' => 'flex-1'],
            ['key' => 'name', 'label' => 'نام', 'class' => 'flex-1'],
        ];
    }

    public function permissions(): LengthAwarePaginator
    {
        $query = Permission::query();

        if (! empty($this->search)) {
            $query->where('name', 'LIKE', '%'.self::escapeLikeWildcards($this->search).'%')
                ->orWhere('label', 'LIKE', '%'.self::escapeLikeWildcards($this->search).'%');
        }

        $column = in_array($this->sortBy['column'] ?? '', self::SORTABLE_COLUMNS, true)
            ? $this->sortBy['column'] : 'id';
        $direction = ($this->sortBy['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction);

        return $query->paginate($this->pageSize());
    }

    public function with(): array
    {
        return [
            'editingId' => $this->editingId,
            'permissions' => $this->permissions(),
            'headers' => $this->headers(),
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت دسترسی ها" separator progress-indicator>
        <x-slot:middle class="!justify-end"></x-slot:middle>
        <x-slot:actions>
            <x-help:button section="permissions" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" section="permissions" />

    <x-card shadow>
        <div class="breadcrumbs flex gap-2 items-center">
            <x-ui.icon-button name="مجوز جدید" label="مجوز جدید" class="btn-success" @click="$wire.modal = true" responsive icon="o-plus"/>
            <div class="flex-1">
                <x-input
                    placeholder="جستجو..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
        </div>

        <x-table :headers="$headers" :rows="$permissions" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[10, 20, 50]">
            @foreach($permissions as $permission)
                <tr wire:key="{{ $permission->id }}">
                    @scope('actions', $permission)
                    <div class="flex w-1/4">
                        <x-ui.icon-button name="ویرایش مجوز" icon="o-pencil"
                                  wire:click="editPermission({{ $permission->id }})"
                                  class="btn-ghost btn-sm text-primary"
                                  @click="$wire.modal = true" />

                        <x-ui.icon-button name="حذف مجوز" icon="o-trash"
                                  wire:click="delete({{ $permission->id }})"
                                  wire:confirm="Are you sure?"
                                  spinner
                                  class="btn-ghost btn-sm text-error" />
                    </div>
                    @endscope
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-modal wire:model="modal" :title="$editingId ? 'ویرایش ' : 'جدید'" persistent separator>
        <x-form wire:submit.prevent="{{ $editingId ? 'updatePermission' : 'createPermission' }}" class="grid gap-4">
            <x-input
                wire:model="name"
                label="نام سطح دسترسی"
                placeholder="نام انگلیسی سطح دسترسی"
                required
                icon="o-magnifying-glass"
            />
            <x-input
                wire:model="label"
                label="عنوان سطح دسترسی"
                placeholder="عنوان فارسی سطح دسترسی"
                required
                icon="o-magnifying-glass"
            />

            <div class="flex gap-4">
                <x-button type="submit" label="ذخیره" icon="o-check" class="btn-primary pl-6" spinner/>
                <x-button label="بستن" icon="o-x-mark" wire:click="clear" class="btn-default pl-6" spinner/>
            </div>
        </x-form>
    </x-modal>
</div>