<?php

namespace App\Livewire\Kargozini;

use App\Models\Estekhdam;
use App\Models\Person as PersonModel;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * Personnel management page (مدیریت پرسنل).
 *
 * Extracted from the anonymous class defined inline in
 * resources/views/livewire/kargozini/person.blade.php so the component is
 * testable in isolation and IDE-tooling friendly. Behavior is unchanged.
 */
class Person extends Component
{
    use Toast;
    use WithPagination;

    public bool $showHelpModal = false;

    public $n_code;

    public $f_name;

    public $l_name;

    public $t_id;

    public $e_id;

    public $s_id;

    public $r_id;

    public $u_id;

    public ?int $editingId = null;

    public string $search = '';

    public int $perPage = 20;

    public bool $formOpen = false;

    public bool $unitModal = false;

    // List filter properties (#494) — kept separate from the create/edit form
    // properties ($u_id, $s_id, ...) so opening the edit form does not filter the list.
    public $filter_u_id;

    public $filter_s_id;

    public $filter_t_id;

    public $filter_e_id;

    public $filter_r_id;

    public bool $filterUnitModal = false;

    public bool $showFilters = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    public function clearFilters(): void
    {
        $this->reset(['filter_u_id', 'filter_s_id', 'filter_t_id', 'filter_e_id', 'filter_r_id', 'filterUnitModal']);
    }

    public function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['n_code', 'f_name', 'l_name', 't_id', 'e_id', 's_id', 'r_id', 'u_id', 'editingId', 'formOpen', 'unitModal', 'showFilters', 'filter_u_id', 'filter_s_id', 'filter_t_id', 'filter_e_id', 'filter_r_id', 'filterUnitModal']);
    }

    public function startCreate(): void
    {
        $this->resetForm();
        $this->formOpen = true;
    }

    public function delete(PersonModel $person): void
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        if (! in_array($person->u_id, $accessibleIds)) {
            $this->error('شما مجاز به حذف این پرسنل نیستید.', position: 'toast-bottom');

            return;
        }

        try {
            $person->delete();
            $this->warning("$person->f_name $person->l_name حذف شد", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error('امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.', position: 'toast-bottom');
        }
    }

    public function savePerson(): void
    {
        $this->validate([
            'n_code' => 'required|string|size:10|unique:persons,n_code,'.($this->editingId ?: 'NULL'),
            'f_name' => 'required|string|max:255',
            'l_name' => 'required|string|max:255',
            't_id' => 'required|exists:tahsils,id',
            'e_id' => 'required|exists:estekhdams,id',
            's_id' => 'required|exists:semats,id',
            'r_id' => 'required|exists:radifs,id',
            'u_id' => 'required|exists:units,id',
        ]);

        if ($this->editingId) {
            $person = PersonModel::findOrFail($this->editingId);

            // Check organizational scope for update
            $accessibleIds = app(AccessService::class)->accessibleUnitIds();
            if (! in_array($person->u_id, $accessibleIds)) {
                $this->error('شما مجاز به ویرایش این پرسنل نیستید.', position: 'toast-bottom');

                return;
            }

            $person->update([
                'n_code' => $this->n_code,
                'f_name' => $this->f_name,
                'l_name' => $this->l_name,
                't_id' => $this->t_id,
                'e_id' => $this->e_id,
                's_id' => $this->s_id,
                'r_id' => $this->r_id,
                'u_id' => $this->u_id,
            ]);

            if ($user = $person->user) {
                app(AccessService::class)->clearCache($user);
                $user->units()->syncWithoutDetaching([$this->u_id => ['role' => 'staff', 'is_primary' => true]]);
            }

            $this->success('شخص به‌روزرسانی شد');
        } else {
            // Check organizational scope for create - u_id must be in accessible units
            $accessibleIds = app(AccessService::class)->accessibleUnitIds();
            if (! in_array($this->u_id, $accessibleIds)) {
                $this->error('شما مجاز به ثبت پرسنل در این واحد نیستید.', position: 'toast-bottom');

                return;
            }

            $person = PersonModel::create([
                'n_code' => $this->n_code,
                'f_name' => $this->f_name,
                'l_name' => $this->l_name,
                't_id' => $this->t_id,
                'e_id' => $this->e_id,
                's_id' => $this->s_id,
                'r_id' => $this->r_id,
                'u_id' => $this->u_id,
            ]);

            if ($user = $person->user) {
                app(AccessService::class)->clearCache($user);
                $user->units()->syncWithoutDetaching([$this->u_id => ['role' => 'staff', 'is_primary' => true]]);
            }

            $this->success('شخص جدید ثبت شد');
        }

        $this->resetForm();
    }

    public function editPerson($id): void
    {
        $this->resetValidation();
        $person = PersonModel::findOrFail($id);

        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        if (! in_array($person->u_id, $accessibleIds)) {
            $this->error('شما مجاز به ویرایش این پرسنل نیستید.', position: 'toast-bottom');

            return;
        }

        $this->editingId = (int) $id;
        $this->n_code = $person->n_code;
        $this->f_name = $person->f_name;
        $this->l_name = $person->l_name;
        $this->t_id = $person->t_id;
        $this->e_id = $person->e_id;
        $this->s_id = $person->s_id;
        $this->r_id = $person->r_id;
        $this->u_id = $person->u_id;
        $this->formOpen = true;
        $this->unitModal = false;
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden 2xl:table-cell'],
            ['key' => 'n_code', 'label' => 'کد ملی', 'class' => 'w-10 hidden sm:table-cell'],
            ['key' => 'f_name', 'label' => 'نام', 'class' => 'w-10'],
            ['key' => 'l_name', 'label' => 'نام خانوادگی', 'class' => 'w-10'],
            ['key' => 'tahsil_name', 'label' => 'تحصیلات', 'class' => 'w-10 hidden xl:table-cell'],
            ['key' => 'estekhdam_name', 'label' => 'استخدام', 'class' => 'w-10 hidden xl:table-cell'],
            ['key' => 'semat_name', 'label' => 'سمت', 'class' => 'w-10 hidden xl:table-cell'],
            ['key' => 'radif_name', 'label' => 'ردیف سازمانی', 'class' => 'w-10 hidden xl:table-cell'],
            ['key' => 'unit_name', 'label' => 'واحد', 'class' => 'w-10 hidden xl:table-cell'],
        ];
    }

    public function persons(): LengthAwarePaginator
    {
        $query = PersonModel::query()
            ->accessible('u_id')
            ->withAggregate('tahsil', 'name')
            ->withAggregate('estekhdam', 'name')
            ->withAggregate('semat', 'name')
            ->withAggregate('radif', 'name')
            ->withAggregate('unit', 'name');

        if (! empty($this->search)) {
            // normalizeForSearch also converts Persian/Arabic digits to Latin.
            $search = PersianNormalizer::normalizeForSearch($this->search);

            // Each whitespace-separated term must match (AND); within a term,
            // any of n_code / "first last" / "last first" / unit name counts,
            // so "عسگری مهدی" finds «مهدی عسگری» too (#494).
            $terms = array_values(array_filter(explode(' ', $search), fn ($t) => $t !== ''));

            $query->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->where(function ($tq) use ($term) {
                        $tq->where('n_code', 'LIKE', '%'.$term.'%')
                            ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$term}%"])
                            ->orWhereRaw("CONCAT(l_name, ' ', f_name) LIKE ?", ["%{$term}%"])
                            ->orWhereHas('unit', function ($uq) use ($term) {
                                $uq->where('name', 'LIKE', "%{$term}%");
                            });
                    });
                }
            });
        }

        // Unit, Semat, Tahsil, Estekhdam, Radif Filters (#494)
        if ($this->filter_u_id) {
            $query->where('u_id', $this->filter_u_id);
        }
        if ($this->filter_s_id) {
            $query->where('s_id', $this->filter_s_id);
        }
        if ($this->filter_t_id) {
            $query->where('t_id', $this->filter_t_id);
        }
        if ($this->filter_e_id) {
            $query->where('e_id', $this->filter_e_id);
        }
        if ($this->filter_r_id) {
            $query->where('r_id', $this->filter_r_id);
        }

        $query->orderBy(...array_values($this->sortBy));

        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        $accessibleUnitIds = app(AccessService::class)->accessibleUnitIds();

        $units = Unit::with('unitType')
            ->whereIn('id', $accessibleUnitIds)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'parent_id' => $u->parent_id,
                'unit_type_name' => $u->unitType?->name,
            ])
            ->all();

        $selectedUnitName = null;
        if ($this->u_id) {
            $selectedUnitName = collect($units)->firstWhere('id', (int) $this->u_id)['name'] ?? Unit::find($this->u_id)?->name;
        }

        $filterUnitName = null;
        if ($this->filter_u_id) {
            $filterUnitName = collect($units)->firstWhere('id', (int) $this->filter_u_id)['name'] ?? Unit::find($this->filter_u_id)?->name;
        }

        return [
            'persons' => $this->persons(),
            'headers' => $this->headers(),
            'tahsils' => Tahsil::all(),
            'estekhdams' => Estekhdam::all(),
            'semats' => Semat::all(),
            'radifs' => Radif::all(),
            'units' => $units,
            'selectedUnitName' => $selectedUnitName,
            'filterUnitName' => $filterUnitName,
        ];
    }

    public function render()
    {
        return view('livewire.kargozini.person')->layoutData([
            'title' => 'مدیریت پرسنل',
        ]);
    }
}
