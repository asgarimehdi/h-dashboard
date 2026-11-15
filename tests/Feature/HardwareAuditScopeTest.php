<?php

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\HardwareAuditScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(HardwareAuditScope::class);

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seedLookupTables();
    Permission::firstOrCreate(['name' => 'manage_hardware']);
});

/**
 * The tests below pin the deny-by-default contract of HardwareAuditScope
 * (issue #1503). The scope itself is actor-free: only the two consumers
 * (Livewire trash + API restore) intersect it with the caller's units.
 */
function scope1503Unit(string $name = 'Scope A'): Unit
{
    return Unit::create(['name' => $name]);
}

function scope1503Person(Unit $unit): Person
{
    return Person::factory()->create(['u_id' => $unit->id]);
}

/**
 * @param  array<int, array<string, mixed>>|null  $changes
 */
function scope1503Audit(int $hardwareId, ?array $changes, string $action = 'created'): HardwareAudit
{
    return HardwareAudit::create([
        'hardware_id' => $hardwareId,
        'action' => $action,
        'changes' => $changes,
        'source' => 'web',
    ]);
}

/**
 * @return array<string, mixed>
 */
function scope1503NCodeChange(mixed $new): array
{
    return ['field' => 'n_code', 'old' => null, 'new' => $new];
}

function scope1503HardwareFor(Person $person, string $pcName): Hardware
{
    return Hardware::create([
        'n_code' => $person->n_code,
        'pc_name' => $pcName,
        'type' => 'pc',
        'mac' => strtolower($pcName).'-mac',
        'shutdown' => false,
        'mark' => false,
    ]);
}

/**
 * An authenticated caller holding manage_hardware but attached to no unit,
 * whose person row carries `u_id = null`. Its accessible scope is `[]`.
 *
 * `users.n_code` is FK-bound to `persons`, so the person row is mandatory —
 * the unit link is what must stay empty.
 *
 * @return array{0: User, 1: string}
 */
function scope1503UnscopedCaller(): array
{
    $person = Person::factory()->create(['u_id' => null]);

    $user = User::create([
        'n_code' => $person->n_code,
        'password' => Hash::make('password'),
    ]);
    $user->givePermissionTo('manage_hardware');

    return [$user, $user->createToken('test')->plainTextToken];
}

/**
 * @param  array<int, array<string, mixed>>  $queries
 */
function scope1503ScopeQueryCount(array $queries, string $table): int
{
    return collect($queries)
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "'.$table.'"'))
        ->count();
}

// ── A: empty input ──────────────────────────────────────────────────

it('A1: resolves an empty list to an empty map', function () {
    expect(app(HardwareAuditScope::class)->unitIdsForAudits([]))->toBe([]);
});

// ── B: the scope itself never decides, the consumer intersects ──────

it('B1: an audit with a valid n_code is inaccessible when the caller scope is empty', function () {
    $unit = scope1503Unit();
    $person = scope1503Person($unit);
    $audit = scope1503Audit(900101, [scope1503NCodeChange($person->n_code)]);

    expect(app(HardwareAuditScope::class)->isAccessible($audit, []))->toBeFalse();
});

it('B2: resolves the owning unit even when that unit is foreign to the caller', function () {
    $unitB = scope1503Unit('Scope B');
    $person = scope1503Person($unitB);
    $audit = scope1503Audit(900102, [scope1503NCodeChange($person->n_code)]);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBe((int) $unitB->id);
});

it('B3: the same audit is accessible once its unit is in the caller scope', function () {
    $unitB = scope1503Unit('Scope B');
    $person = scope1503Person($unitB);
    $audit = scope1503Audit(900103, [scope1503NCodeChange($person->n_code)]);

    expect(app(HardwareAuditScope::class)->isAccessible($audit, [$unitB->id]))->toBeTrue();
});

// ── C: snapshot parsing, deny by default ─────────────────────────────

it('C1: a deleted hardware with a null snapshot proves nothing', function () {
    $audit = scope1503Audit(900201, null);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBeNull();
});

it('C2: an empty snapshot proves nothing', function () {
    $audit = scope1503Audit(900202, []);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBeNull();
});

it('C3: a snapshot without an n_code field proves nothing', function () {
    $audit = scope1503Audit(900203, [
        ['field' => 'pc_name', 'old' => null, 'new' => 'PC-WITHOUT-NCODE'],
        ['field' => 'type', 'old' => null, 'new' => 'pc'],
    ]);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBeNull();
});

it('C4: the em dash placeholder (U+2014) proves nothing', function () {
    $audit = scope1503Audit(900204, [scope1503NCodeChange("\u{2014}")]);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBeNull();
});

it('C5: a non-string n_code proves nothing', function () {
    $audit = scope1503Audit(900205, [scope1503NCodeChange(1234567890)]);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBeNull();
});

it('C6: a null n_code entry is skipped and the next entry wins', function () {
    $unit = scope1503Unit();
    $person = scope1503Person($unit);
    $audit = scope1503Audit(900206, [
        scope1503NCodeChange(null),
        scope1503NCodeChange($person->n_code),
    ]);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBe((int) $unit->id);
});

// ── D: n_code → person → unit ───────────────────────────────────────

it('D1: an n_code without a person row proves nothing', function () {
    $audit = scope1503Audit(900301, [scope1503NCodeChange('0000000001')]);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBeNull();
});

it('D2: a person without a unit proves nothing', function () {
    $person = Person::factory()->create(['u_id' => null]);
    $audit = scope1503Audit(900302, [scope1503NCodeChange($person->n_code)]);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBeNull();
});

it('D3: a person attached to a unit resolves to that unit', function () {
    $unit = scope1503Unit();
    $person = scope1503Person($unit);
    $audit = scope1503Audit(900303, [scope1503NCodeChange($person->n_code)]);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBe((int) $unit->id);
});

// ── E: live row priority + batch shape / query budget ──────────────

it('E1: the live hardware n_code wins over the audit snapshot', function () {
    $liveUnit = scope1503Unit('Live Unit A');
    $snapshotUnit = scope1503Unit('Snapshot Unit B');
    $livePerson = scope1503Person($liveUnit);
    $snapshotPerson = scope1503Person($snapshotUnit);

    $hardware = scope1503HardwareFor($livePerson, 'LIVE-PRIORITY-PC');

    // `Hardware::create()` also emits its own `created` audit — harmless,
    // this test asks about the audit it creates itself.
    $audit = scope1503Audit($hardware->id, [scope1503NCodeChange($snapshotPerson->n_code)]);

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBe((int) $liveUnit->id);
});

it('E2: a batch keeps every audit key and spends at most two queries', function () {
    $unit = scope1503Unit();
    $person = scope1503Person($unit);

    $valid = scope1503Audit(900501, [scope1503NCodeChange($person->n_code)]);
    $unknownPerson = scope1503Audit(900502, [scope1503NCodeChange('0000000002')]);
    $withoutNCode = scope1503Audit(900503, [['field' => 'pc_name', 'old' => null, 'new' => 'PC-3']]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $result = app(HardwareAuditScope::class)->unitIdsForAudits([$valid, $unknownPerson, $withoutNCode]);

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect($result)->toBe([
        $valid->id => (int) $unit->id,
        $unknownPerson->id => null,
        $withoutNCode->id => null,
    ]);

    $scopeQueries = scope1503ScopeQueryCount($log, 'hardwares') + scope1503ScopeQueryCount($log, 'persons');

    expect($scopeQueries)->toBeLessThanOrEqual(2);
});

it('E3: a batch with no usable snapshot runs exactly one query', function () {
    $nullChanges = scope1503Audit(900601, null);
    $emptyChanges = scope1503Audit(900602, []);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $result = app(HardwareAuditScope::class)->unitIdsForAudits([$nullChanges, $emptyChanges]);

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect($result)->toBe([
        $nullChanges->id => null,
        $emptyChanges->id => null,
    ]);

    expect(scope1503ScopeQueryCount($log, 'hardwares'))->toBe(1);
    expect(scope1503ScopeQueryCount($log, 'persons'))->toBe(0);
});

// ── F: the two consumers fail closed ───────────────────────────────

it('F1: the Livewire trash hides a resolvable audit when the caller scope is empty', function () {
    // The audit is perfectly resolvable — a real unit owns it — so an empty
    // trash can only mean the caller's scope is empty.
    $realUnit = scope1503Unit('Real Trash Unit');
    $realPerson = scope1503Person($realUnit);
    scope1503HardwareFor($realPerson, 'TRASH-EMPTY-SCOPE-PC')->delete();

    [$user] = scope1503UnscopedCaller();
    $this->actingAs($user);

    expect($user->units()->count())->toBe(0)
        ->and($user->person?->u_id)->toBeNull()
        ->and(session('current_unit_id'))->toBeNull();

    Livewire::test('hardware.index')
        ->call('loadDeletedHardware')
        ->assertSet('trashRows', [])
        ->assertSet('trashTotal', 0);
});

it('F2a: restore-record is denied for a caller with no unit even when the audit resolves', function () {
    $unit = scope1503Unit('API Scope Unit');
    $person = scope1503Person($unit);
    $audit = scope1503Audit(900701, [scope1503NCodeChange($person->n_code)]);

    [$user, $token] = scope1503UnscopedCaller();

    expect(app(HardwareAuditScope::class)->unitIdForAudit($audit))->toBe((int) $unit->id);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson("/api/hardware/audits/{$audit->id}/restore-record")
        ->assertStatus(403)
        ->assertJsonPath('message', 'Hardware record not accessible.');
});

it('F2b: restore-record is denied when the audit n_code has no person row', function () {
    // The caller does hold a unit, so the only unprovable side is the audit.
    $caller = $this->createUserWithUnit(['manage_hardware']);
    $token = $caller['user']->createToken('test')->plainTextToken;

    $audit = scope1503Audit(900702, [scope1503NCodeChange('0000000003')]);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson("/api/hardware/audits/{$audit->id}/restore-record")
        ->assertStatus(403)
        ->assertJsonPath('message', 'Hardware record not accessible.');
});
