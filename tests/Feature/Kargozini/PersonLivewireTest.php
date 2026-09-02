<?php

namespace Tests\Feature\Kargozini;

use App\Models\Person;
use App\Models\Person as PersonModel;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the مدیریت پرسنل (personnel management) Livewire component.
 * Covers render/with(), search normalization, the #494 list filters,
 * create/update/delete with organizational-scope enforcement, the
 * FK-violation guard on delete, and linked-user unit sync.
 */
covers(Person::class);

class PersonLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected Unit $unit;

    protected Unit $otherUnit;

    protected User $user;

    protected int $tId;

    protected int $eId;

    protected int $sId;

    protected int $rId;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        $this->tId = DB::table('tahsils')->insertGetId(['name' => 'کارشناسی']);
        $this->eId = DB::table('estekhdams')->insertGetId(['name' => 'رسمی']);
        $this->sId = DB::table('semats')->insertGetId(['name' => 'کارشناس']);
        $this->rId = DB::table('radifs')->insertGetId(['name' => '۱']);

        $this->unit = Unit::create(['name' => 'واحد مرکزی']);
        // A sibling unit (not a descendant of $this->unit) -> out of scope.
        $this->otherUnit = Unit::create(['name' => 'واحد منطقه‌ای']);

        $nCode = (string) fake()->unique()->numerify('##########');
        PersonModel::create([
            'n_code' => $nCode, 'f_name' => 'مهدی', 'l_name' => 'عسگری',
            't_id' => $this->tId, 'e_id' => $this->eId, 's_id' => $this->sId, 'r_id' => $this->rId,
            'u_id' => $this->unit->id,
        ]);
        $this->user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);
        $this->actingAs($this->user);
    }

    /**
     * Fill the component form fields from a person attribute array.
     */
    protected function fillForm($component, array $data)
    {
        return $component
            ->set('n_code', $data['n_code'])
            ->set('f_name', $data['f_name'])
            ->set('l_name', $data['l_name'])
            ->set('t_id', $data['t_id'])
            ->set('e_id', $data['e_id'])
            ->set('s_id', $data['s_id'])
            ->set('r_id', $data['r_id'])
            ->set('u_id', $data['u_id']);
    }

    protected function personData(array $overrides = []): array
    {
        return array_merge([
            'n_code' => (string) fake()->unique()->numerify('##########'),
            'f_name' => 'رضا',
            'l_name' => 'کریمی',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $this->unit->id,
        ], $overrides);
    }

    public function test_component_renders_with_dropdowns(): void
    {
        Livewire::test('kargozini.person')
            ->assertOk()
            ->assertViewHas('persons')
            ->assertViewHas('headers')
            ->assertViewHas('units')
            ->assertViewHas('tahsils')
            ->assertViewHas('estekhdams')
            ->assertViewHas('semats')
            ->assertViewHas('radifs');
    }

    public function test_search_finds_by_name_in_either_order(): void
    {
        // "عسگری مهدی" (last first) must find «مهدی عسگری» (#494).
        Livewire::test('kargozini.person')
            ->set('search', 'عسگری مهدی')
            ->assertViewHas('persons', fn ($p) => $p->count() === 1);
    }

    public function test_search_normalizes_persian_digits_in_query(): void
    {
        // Codes are stored with Latin digits; a Persian-digit search term
        // must be normalized before matching.
        $person = PersonModel::where('u_id', $this->unit->id)->first();
        $latinCode = $person->n_code;
        $persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $persianCode = collect(mb_str_split($latinCode))
            ->map(fn ($d) => $persianDigits[(int) $d])
            ->implode('');

        Livewire::test('kargozini.person')
            ->set('search', $persianCode)
            ->assertViewHas('persons', fn ($p) => $p->count() === 1);
    }

    public function test_search_without_match_returns_empty(): void
    {
        Livewire::test('kargozini.person')
            ->set('search', 'ناهمخوان کامل')
            ->assertViewHas('persons', fn ($p) => $p->count() === 0);
    }

    public function test_filter_by_unit_narrows_results(): void
    {
        Livewire::test('kargozini.person')
            ->set('filter_u_id', $this->unit->id)
            ->assertViewHas('persons', fn ($p) => $p->count() === 1);

        Livewire::test('kargozini.person')
            ->set('filter_u_id', $this->otherUnit->id) // no persons there
            ->assertViewHas('persons', fn ($p) => $p->count() === 0);
    }

    public function test_filter_by_semat_narrows_results(): void
    {
        Livewire::test('kargozini.person')
            ->set('filter_s_id', $this->sId)
            ->assertViewHas('persons', fn ($p) => $p->count() === 1);

        Livewire::test('kargozini.person')
            ->set('filter_s_id', 99999) // different semat -> no match
            ->assertViewHas('persons', fn ($p) => $p->count() === 0);
    }

    public function test_filter_by_tahsil_estekhdam_radif(): void
    {
        Livewire::test('kargozini.person')
            ->set('filter_t_id', $this->tId)
            ->set('filter_e_id', $this->eId)
            ->set('filter_r_id', $this->rId)
            ->assertViewHas('persons', fn ($p) => $p->count() === 1);
    }

    public function test_clear_filters_resets_filter_properties(): void
    {
        Livewire::test('kargozini.person')
            ->set('filter_u_id', $this->unit->id)
            ->set('filter_s_id', $this->sId)
            ->set('showFilters', true)
            ->call('clearFilters')
            ->assertSet('filter_u_id', null)
            ->assertSet('filter_s_id', null)
            ->assertSet('filter_t_id', null)
            ->assertSet('filter_e_id', null)
            ->assertSet('filter_r_id', null);
    }

    public function test_start_create_opens_form_and_resets(): void
    {
        Livewire::test('kargozini.person')
            ->set('n_code', '9999999999')
            ->call('startCreate')
            ->assertSet('formOpen', true)
            ->assertSet('n_code', null)
            ->assertSet('editingId', null);
    }

    public function test_save_person_creates_in_accessible_unit(): void
    {
        $data = $this->personData();

        $component = Livewire::test('kargozini.person');
        $this->fillForm($component, $data);
        $component->call('savePerson');

        $this->assertDatabaseHas('persons', [
            'n_code' => $data['n_code'], 'f_name' => $data['f_name'], 'u_id' => $this->unit->id,
        ]);
    }

    public function test_save_person_denied_in_inaccessible_unit(): void
    {
        $data = $this->personData(['u_id' => $this->otherUnit->id]); // out of scope

        $component = Livewire::test('kargozini.person');
        $this->fillForm($component, $data);
        $component->call('savePerson');

        $this->assertDatabaseMissing('persons', ['n_code' => $data['n_code']]);
    }

    public function test_save_person_validates_required_fields(): void
    {
        Livewire::test('kargozini.person')
            ->set('n_code', '')
            ->set('f_name', '')
            ->set('l_name', '')
            ->set('u_id', $this->unit->id)
            ->call('savePerson')
            ->assertHasErrors(['n_code', 'f_name', 'l_name', 't_id', 'e_id', 's_id', 'r_id']);
    }

    public function test_save_person_updates_existing(): void
    {
        $person = PersonModel::where('u_id', $this->unit->id)->first();

        $component = Livewire::test('kargozini.person')
            ->call('editPerson', $person->id);
        $this->fillForm($component, [
            'n_code' => $person->n_code,
            'f_name' => 'UpdatedFirst',
            'l_name' => 'UpdatedLast',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $this->unit->id,
        ]);
        $component->call('savePerson');

        $this->assertDatabaseHas('persons', [
            'id' => $person->id, 'f_name' => 'UpdatedFirst', 'l_name' => 'UpdatedLast',
        ]);
    }

    public function test_save_person_update_denied_for_out_of_scope(): void
    {
        // Person owned by otherUnit (out of current scope).
        $otherNCode = (string) fake()->unique()->numerify('##########');
        $other = PersonModel::create([
            'n_code' => $otherNCode, 'f_name' => 'بیگانه', 'l_name' => 'صحرا',
            't_id' => $this->tId, 'e_id' => $this->eId, 's_id' => $this->sId, 'r_id' => $this->rId,
            'u_id' => $this->otherUnit->id,
        ]);

        $component = Livewire::test('kargozini.person');
        $this->fillForm($component, [
            'n_code' => $otherNCode,
            'f_name' => 'Changed',
            'l_name' => 'صحرا',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $this->otherUnit->id,
        ])->set('editingId', $other->id)->call('savePerson');

        $this->assertDatabaseHas('persons', [
            'id' => $other->id, 'f_name' => 'بیگانه', // unchanged
        ]);
    }

    public function test_edit_person_loads_fields_when_in_scope(): void
    {
        $person = PersonModel::where('u_id', $this->unit->id)->first();

        Livewire::test('kargozini.person')
            ->call('editPerson', $person->id)
            ->assertSet('editingId', $person->id)
            ->assertSet('n_code', $person->n_code)
            ->assertSet('f_name', 'مهدی')
            ->assertSet('formOpen', true);
    }

    public function test_edit_person_denied_out_of_scope(): void
    {
        $otherNCode = (string) fake()->unique()->numerify('##########');
        $other = PersonModel::create([
            'n_code' => $otherNCode, 'f_name' => 'ممنوع', 'l_name' => 'ورود',
            't_id' => $this->tId, 'e_id' => $this->eId, 's_id' => $this->sId, 'r_id' => $this->rId,
            'u_id' => $this->otherUnit->id,
        ]);

        Livewire::test('kargozini.person')
            ->call('editPerson', $other->id)
            ->assertSet('editingId', null) // not loaded
            ->assertSet('formOpen', false);
    }

    public function test_delete_removes_unreferenced_person_in_scope(): void
    {
        // Fresh person with no dependent rows (no linked user) -> deletable.
        $fresh = PersonModel::create($this->personData());

        Livewire::test('kargozini.person')
            ->call('delete', $fresh);

        $this->assertDatabaseMissing('persons', ['id' => $fresh->id]);
    }

    public function test_delete_denied_out_of_scope(): void
    {
        $otherNCode = (string) fake()->unique()->numerify('##########');
        $other = PersonModel::create([
            'n_code' => $otherNCode, 'f_name' => 'محفوظ', 'l_name' => 'سیستم',
            't_id' => $this->tId, 'e_id' => $this->eId, 's_id' => $this->sId, 'r_id' => $this->rId,
            'u_id' => $this->otherUnit->id,
        ]);

        Livewire::test('kargozini.person')
            ->call('delete', $other);

        $this->assertDatabaseHas('persons', ['id' => $other->id]);
    }

    public function test_delete_handles_fk_violation_gracefully(): void
    {
        // persons rows referenced elsewhere are onDelete(restrict); the
        // component must swallow the failure and keep the row. Simulate the
        // restriction with a hydrated subclass whose delete() throws, so the
        // wrapped test transaction is not poisoned by a real SQL error.
        $target = PersonModel::create($this->personData());

        $stub = new class extends PersonModel
        {
            protected $table = 'persons';

            public function delete(): bool
            {
                throw new \RuntimeException('simulated FK restriction');
            }
        };
        $throwing = $stub->newQuery()->findOrFail($target->id);

        Livewire::test('kargozini.person')
            ->call('delete', $throwing);

        // Row survives (covers the catch branch).
        $this->assertDatabaseHas('persons', ['id' => $target->id]);
    }

    public function test_save_person_syncs_linked_user_units(): void
    {
        // A user sharing the person's n_code gets attached to the person's
        // unit on save (update branch).
        $userNCode = (string) fake()->unique()->numerify('##########');
        // Person first (users.n_code FK -> persons.n_code), then the user.
        $person = PersonModel::create($this->personData(['n_code' => $userNCode]));
        $linkedUser = User::create(['n_code' => $userNCode, 'password' => Hash::make('password')]);
        $this->assertFalse(
            $linkedUser->units()->where('units.id', $this->unit->id)->exists()
        );

        $component = Livewire::test('kargozini.person')
            ->call('editPerson', $person->id);
        $this->fillForm($component, [
            'n_code' => $userNCode,
            'f_name' => 'همراه',
            'l_name' => 'کاربر',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $this->unit->id,
        ]);
        $component->call('savePerson');

        $this->assertDatabaseHas('persons', ['n_code' => $userNCode, 'f_name' => 'همراه']);
        // The linked user is now attached to the person's unit.
        $this->assertTrue(
            $linkedUser->units()->where('units.id', $this->unit->id)->exists()
        );
    }
}
