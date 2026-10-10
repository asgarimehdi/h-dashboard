<?php

use App\Models\MaintenanceSchedule;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

    foreach (['tahsils', 'estekhdams', 'semats', 'radifs'] as $table) {
        DB::unprepared(
            "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), (SELECT COALESCE(MAX(id),1) FROM {$table}))"
        );
    }

    $this->unit = Unit::create(['name' => 'واحد تست']);

    $this->user = User::factory()->create([
        'n_code' => (string) fake()->unique()->numerify('##########'),
        'password' => Hash::make('password'),
    ]);
    $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
    $this->user->givePermissionTo('manage_hardware');
});

test('guest is redirected from maintenance page', function () {
    $this->get('/maintenance')->assertRedirect('/login');
});

test('authenticated user without manage_hardware permission is denied', function () {
    $user = User::factory()->create([
        'n_code' => (string) fake()->unique()->numerify('##########'),
        'password' => Hash::make('password'),
    ]);
    $unit = Unit::create(['name' => 'واحد دیگر']);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

    $this->actingAs($user);
    $this->get('/maintenance')->assertStatus(403);
});

test('authenticated user with permission can load maintenance page', function () {
    $this->actingAs($this->user);
    Livewire::test('maintenance.index')
        ->assertStatus(200);
});

test('create schedule via livewire', function () {
    $this->actingAs($this->user);

    Livewire::test('maintenance.index')
        ->set('title', 'بازدید ماهانه سرور')
        ->set('frequency', 'monthly')
        ->set('recurrenceInterval', 1)
        ->set('unitId', $this->unit->id)
        ->call('createSchedule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('maintenance_schedules', [
        'title' => 'بازدید ماهانه سرور',
        'frequency' => 'monthly',
        'recurrence_interval' => 1,
        'unit_id' => $this->unit->id,
    ]);
});

test('edit schedule via livewire', function () {
    $this->actingAs($this->user);

    $schedule = MaintenanceSchedule::create([
        'title' => 'عنوان قدیمی',
        'frequency' => 'weekly',
        'recurrence_interval' => 1,
        'unit_id' => $this->unit->id,
        'next_due_at' => now()->addWeek(),
    ]);

    Livewire::test('maintenance.index')
        ->call('editSchedule', $schedule->id)
        ->assertSet('title', 'عنوان قدیمی')
        ->assertSet('frequency', 'weekly')
        ->set('title', 'عنوان جدید')
        ->call('updateSchedule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('maintenance_schedules', [
        'id' => $schedule->id,
        'title' => 'عنوان جدید',
    ]);
});

test('delete schedule via livewire', function () {
    $this->actingAs($this->user);

    $schedule = MaintenanceSchedule::create([
        'title' => 'برای حذف',
        'frequency' => 'daily',
        'recurrence_interval' => 1,
        'unit_id' => $this->unit->id,
    ]);

    Livewire::test('maintenance.index')
        ->call('delete', $schedule->id);

    $this->assertDatabaseMissing('maintenance_schedules', ['id' => $schedule->id]);
});

test('validation fails for empty title', function () {
    $this->actingAs($this->user);

    Livewire::test('maintenance.index')
        ->set('title', '')
        ->call('createSchedule')
        ->assertHasErrors(['title']);
});

test('validation fails for invalid frequency', function () {
    $this->actingAs($this->user);

    Livewire::test('maintenance.index')
        ->set('title', 'تست')
        ->set('frequency', 'invalid')
        ->call('createSchedule')
        ->assertHasErrors(['frequency']);
});

test('frequency select renders non-empty option labels (issue #706)', function () {
    $this->actingAs($this->user);

    $component = Livewire::test('maintenance.index')->call('startCreate');

    // Same #706 root cause as the zabbix type select: 'value'/'label' keys
    // need option-value/option-label, else the options render empty.
    expect($component->html())
        ->toContain('روزانه')
        ->toContain('هفتگی')
        ->toContain('ماهانه');
});

/*
|--------------------------------------------------------------------------
| Unit scope (issue #835, Plan 46)
|--------------------------------------------------------------------------
|
| The component gates only `manage_hardware` and applied no organisational
| scope anywhere: the list returned the whole org, the unit picker offered
| every unit, and all four mutators trusted a client-supplied id / unitId.
| Two units + an org-wide (null unit_id) schedule per test below.
|
*/

function makeSchedule(string $title, ?int $unitId): MaintenanceSchedule
{
    return MaintenanceSchedule::create([
        'title' => $title,
        'frequency' => 'monthly',
        'recurrence_interval' => 1,
        'unit_id' => $unitId,
        'next_due_at' => now()->addMonth(),
    ]);
}

function scheduleIds(): array
{
    return MaintenanceSchedule::pluck('id')->all();
}

/**
 * فقط شناسه‌هایی که کامپوننت واقعاً در جدول رندر می‌کند.
 *
 * `scheduleIds()` کل جدول را برمی‌گرداند و برای اثبات «دیده نمی‌شود» غلط است:
 * ردیف باید در دیتابیس بماند و فقط از فهرست خارج شود.
 *
 * @return array<int>
 */
function renderedScheduleIds(): array
{
    return Livewire::test('maintenance.index')->instance()->schedules()->pluck('id')->all();
}

function adminWithHardware(): User
{
    $admin = User::factory()->create([
        'n_code' => (string) fake()->unique()->numerify('##########'),
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('admin');
    // the seeded admin role carries manage_personnel/manage_zabbix, not
    // manage_hardware, and the component authorizes that permission first
    $admin->givePermissionTo('manage_hardware');

    return $admin;
}

test('list shows in-scope units plus org-wide schedules, never another unit (issue #835)', function () {
    $this->actingAs($this->user);

    makeSchedule('ویژه واحد تست', $this->unit->id);
    makeSchedule('ویژه کل سازمان', null);
    makeSchedule('ویژه واحد دیگر', Unit::create(['name' => 'واحد بیگانه'])->id);

    $html = Livewire::test('maintenance.index')->html();

    expect($html)
        ->toContain('ویژه واحد تست')
        ->toContain('ویژه کل سازمان')
        ->not->toContain('ویژه واحد دیگر');

    expect(renderedScheduleIds())->toHaveCount(2);
});

test('empty scope holder sees only org-wide schedules (issue #835)', function () {
    // A truly scope-less holder is not constructible in this schema: the user
    // factory always creates a Person (users.n_code FKs persons.n_code) and
    // persons.u_id is NOT NULL, so accessibleUnitIds() always falls back to
    // some unit. Bind a stub that returns the empty scope — that is the state
    // #835's fail-open predicate must survive (the #819 trap).
    $holder = User::factory()->create([
        'n_code' => (string) fake()->unique()->numerify('##########'),
        'password' => Hash::make('password'),
    ]);
    $holder->givePermissionTo('manage_hardware');

    $this->swap(AccessService::class, new class extends AccessService
    {
        public function accessibleUnitIds(?User $user = null): array
        {
            return [];
        }
    });

    $orgWide = makeSchedule('ویژه کل سازمان', null);
    makeSchedule('ویژه واحد تست', $this->unit->id);

    $this->actingAs($holder);

    $html = Livewire::test('maintenance.index')->html();

    expect($html)->toContain('ویژه کل سازمان')->not->toContain('ویژه واحد تست');
    expect(renderedScheduleIds())->toBe([$orgWide->id]);
});

test('unit picker offers only accessible units with non-empty labels (issue #835)', function () {
    $this->actingAs($this->user);

    Unit::create(['name' => 'واحد بیگانه']);

    $html = Livewire::test('maintenance.index')->call('startCreate')->html();

    expect($html)
        ->toContain($this->unit->name)
        ->not->toContain('واحد بیگانه');

    // Regression guard for the #706 class of bug: MaryUI defaults to
    // optionValue='id'/optionLabel='name', so options keyed 'value'/'label'
    // must be given explicit option-value/option-label or every <option>
    // renders blank and the picker cannot set a unit at all.
    preg_match('/<select[^>]*unitId.*?<\/select>/s', $html, $m);
    expect($m)->not->toBeEmpty();

    preg_match_all('/<option[^>]*value="(\d*)"[^>]*>(.*?)<\/option>/s', $m[0], $opts, PREG_SET_ORDER);
    $labelled = array_filter($opts, fn (array $o): bool => trim($o[2]) !== '');
    expect($labelled)->not->toBeEmpty();
});

test('delete refuses another unit schedule and the row survives (issue #835)', function () {
    $this->actingAs($this->user);

    $other = makeSchedule('ویژه واحد دیگر', Unit::create(['name' => 'واحد بیگانه'])->id);

    Livewire::test('maintenance.index')->call('delete', $other->id);

    $this->assertDatabaseHas('maintenance_schedules', ['id' => $other->id, 'title' => 'ویژه واحد دیگر']);
});

test('delete refuses an org-wide schedule for a scoped holder but allows admin (issue #835)', function () {
    $orgWide = makeSchedule('ویژه کل سازمان', null);

    // Scoped holder: refused, row survives.
    $this->actingAs($this->user);
    Livewire::test('maintenance.index')->call('delete', $orgWide->id);
    $this->assertDatabaseHas('maintenance_schedules', ['id' => $orgWide->id]);

    // Admin: allowed.
    $this->actingAs(adminWithHardware());

    Livewire::test('maintenance.index')->call('delete', $orgWide->id);

    $this->assertDatabaseMissing('maintenance_schedules', ['id' => $orgWide->id]);
});

test('edit and update refuse another unit schedule and leave the row unchanged (issue #835)', function () {
    $this->actingAs($this->user);

    $other = makeSchedule('ویژه واحد دیگر', Unit::create(['name' => 'واحد بیگانه'])->id);

    Livewire::test('maintenance.index')->call('editSchedule', $other->id)
        ->assertSet('editingId', null)
        ->assertSet('title', '');

    Livewire::test('maintenance.index')
        ->set('editingId', $other->id)
        ->set('title', 'دستکاری شده')
        ->call('updateSchedule');

    $this->assertDatabaseHas('maintenance_schedules', [
        'id' => $other->id,
        'title' => 'ویژه واحد دیگر',
    ]);
});

test('update refuses to relocate a schedule out of or into an unreachable unit (issue #835)', function () {
    $foreign = Unit::create(['name' => 'واحد بیگانه']);

    $this->actingAs($this->user);

    // Out of scope: in-scope record, submitted unitId belongs to another unit.
    $mine = makeSchedule('ویژه واحد تست', $this->unit->id);
    Livewire::test('maintenance.index')
        ->set('editingId', $mine->id)
        ->set('title', 'ویژه واحد تست')
        ->call('updateSchedule')
        ->assertSet('unitId', null);
    Livewire::test('maintenance.index')
        ->set('editingId', $mine->id)
        ->set('unitId', $foreign->id)
        ->set('title', 'ویژه واحد تست')
        ->call('updateSchedule');

    $this->assertDatabaseHas('maintenance_schedules', ['id' => $mine->id, 'unit_id' => $this->unit->id]);

    // Into scope: null-unit record an admin edits into a scoped unit.
    $orgWide = makeSchedule('ویژه کل سازمان', null);
    Livewire::test('maintenance.index')
        ->set('editingId', $orgWide->id)
        ->set('unitId', $foreign->id)
        ->set('title', 'ویژه کل سازمان')
        ->call('updateSchedule');

    $this->assertDatabaseHas('maintenance_schedules', ['id' => $orgWide->id, 'unit_id' => null]);
});

test('create refuses a submitted unitId outside scope (issue #835)', function () {
    $foreign = Unit::create(['name' => 'واحد بیگانه']);

    $this->actingAs($this->user);

    Livewire::test('maintenance.index')
        ->set('title', 'ساخت غیرمجاز')
        ->set('frequency', 'monthly')
        ->set('recurrenceInterval', 1)
        ->set('unitId', $foreign->id)
        ->call('createSchedule');

    $this->assertDatabaseMissing('maintenance_schedules', ['title' => 'ساخت غیرمجاز']);
});

test('create accepts an accessible unit (issue #835)', function () {
    $this->actingAs($this->user);

    Livewire::test('maintenance.index')
        ->set('title', 'ویژه واحد تست')
        ->set('frequency', 'monthly')
        ->set('recurrenceInterval', 1)
        ->set('unitId', $this->unit->id)
        ->call('createSchedule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('maintenance_schedules', ['title' => 'ویژه واحد تست', 'unit_id' => $this->unit->id]);
});

test('org-wide schedules are rejected for every holder including admin (issues #835, #955)', function () {
    // Issue #955, decision الف: an org-wide maintenance schedule is not a
    // product requirement (tickets.unit_id is NOT NULL, so such a schedule
    // can never produce a ticket). The form requires a unit for everyone —
    // the #835 admin exception for writing null-unit rows is gone. Legacy
    // null-unit rows remain listed (and command-guarded), they just cannot
    // be created or re-saved as null through this form.
    $this->actingAs($this->user);

    Livewire::test('maintenance.index')
        ->set('title', 'ویژه کل سازمان')
        ->set('frequency', 'monthly')
        ->set('recurrenceInterval', 1)
        ->set('unitId', null)
        ->call('createSchedule')
        ->assertHasErrors(['unitId']);

    $this->assertDatabaseMissing('maintenance_schedules', ['title' => 'ویژه کل سازمان']);

    $this->actingAs(adminWithHardware());

    Livewire::test('maintenance.index')
        ->set('title', 'ویژه کل سازمان')
        ->set('frequency', 'monthly')
        ->set('recurrenceInterval', 1)
        ->set('unitId', null)
        ->call('createSchedule')
        ->assertHasErrors(['unitId']);

    $this->assertDatabaseMissing('maintenance_schedules', ['title' => 'ویژه کل سازمان']);
});

test('update that clears the unit is rejected and the row keeps its unit (issue #955)', function () {
    $this->actingAs($this->user);

    $schedule = makeSchedule('دارای واحد', $this->unit->id);

    Livewire::test('maintenance.index')
        ->set('editingId', $schedule->id)
        ->set('title', 'دارای واحد')
        ->set('frequency', 'monthly')
        ->set('recurrenceInterval', 1)
        ->set('unitId', null)
        ->call('updateSchedule')
        ->assertHasErrors(['unitId']);

    $this->assertDatabaseHas('maintenance_schedules', ['id' => $schedule->id, 'unit_id' => $this->unit->id]);
});

test('unit picker offers no org-wide null option (issue #955)', function () {
    $this->actingAs($this->user);

    $html = Livewire::test('maintenance.index')->call('startCreate')->html();

    expect($html)->not->toContain('— همه —');
});
