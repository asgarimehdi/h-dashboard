<?php

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1698: restore is a write block guarded by a non-atomic exists() pre-check.
 * Two restores of the same audit can both pass the check; the explicit-id
 * INSERT makes the primary key the serialiser, and the loser must surface the
 * existing "still exists" answer instead of a duplicate-key 500.
 *
 * These tests make the collision deterministic instead of racing: a
 * `creating` listener inserts the target id on the same connection right
 * before the model's own INSERT, so the unique violation is guaranteed
 * without sleep or a second process. The second pair of tests proves the
 * transaction boundary: an audit-write failure must roll the restored row back.
 */
uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();
});

/**
 * Point a `creating` listener at the event key Eloquent's observe() uses and
 * return a restore callback. Static model listeners otherwise leak into the
 * next test in the same process.
 *
 * @param  class-string  $model
 * @return array{0: string, 1: array<int, mixed>, 2: callable(): void}
 */
function captureRestoreRaceListener(string $model): array
{
    $dispatcher = $model::getEventDispatcher();
    $key = 'eloquent.creating: '.$model;
    $original = $dispatcher->getRawListeners()[$key] ?? [];

    return [
        $key,
        $original,
        function () use ($dispatcher, $key, $original): void {
            $dispatcher->forget($key);
            foreach ($original as $listener) {
                $dispatcher->listen($key, $listener);
            }
        },
    ];
}

/**
 * Create an in-scope user, a deleted hardware row and its 'created' audit.
 *
 * @return array{user: User, token: string, audit: HardwareAudit, hardwareId: int, nCode: string}
 */
function makeRestoreRaceFixture(): array
{
    $unit = Unit::factory()->create();
    $person = Person::factory()->create(['u_id' => $unit->id]);
    $user = User::factory()->create(['n_code' => $person->n_code]);
    $user->givePermissionTo('manage_hardware');
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    $hardware = Hardware::create([
        'n_code' => $user->n_code,
        'pc_name' => 'RACE_HW',
        'type' => 'pc',
        'mac' => 'race-mac',
        'shutdown' => false,
        'mark' => false,
    ]);
    $hardwareId = $hardware->id;

    $audit = HardwareAudit::where('hardware_id', $hardwareId)
        ->where('action', 'created')
        ->firstOrFail();

    $hardware->delete();

    return [
        'user' => $user,
        'token' => $user->createToken('race')->plainTextToken,
        'audit' => $audit,
        'hardwareId' => $hardwareId,
        'nCode' => $user->n_code,
    ];
}

it('maps a colliding API restore insert to the existing 422', function () {
    $fixture = makeRestoreRaceFixture();

    $auditsBefore = HardwareAudit::where('hardware_id', $fixture['hardwareId'])->count();

    [, , $restore] = captureRestoreRaceListener(Hardware::class);

    Hardware::creating(function (Hardware $hardware) use ($fixture): void {
        if ($hardware->id !== $fixture['hardwareId']) {
            return;
        }

        // Same connection, same transaction: the model's own INSERT hits the
        // primary key immediately, deterministically.
        DB::table('hardwares')->insert([
            'id' => $fixture['hardwareId'],
            'n_code' => $fixture['nCode'],
            'pc_name' => 'RACE_COLLIDING_ROW',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$fixture['token']])
            ->postJson("/api/hardware/audits/{$fixture['audit']->id}/restore-record");
    } finally {
        $restore();
    }

    $response->assertStatus(422)
        ->assertJsonPath('message', 'This hardware record still exists — use rollback instead.');

    expect(Hardware::where('id', $fixture['hardwareId'])->exists())->toBeFalse();
    expect(HardwareAudit::where('hardware_id', $fixture['hardwareId'])->count())->toBe($auditsBefore);
    expect(HardwareAudit::where('hardware_id', $fixture['hardwareId'])->where('action', 'rollback')->count())->toBe(0);
});

it('maps a colliding Livewire restore insert to the existing toast', function () {
    $fixture = makeRestoreRaceFixture();

    $auditsBefore = HardwareAudit::where('hardware_id', $fixture['hardwareId'])->count();

    [, , $restore] = captureRestoreRaceListener(Hardware::class);

    Hardware::creating(function (Hardware $hardware) use ($fixture): void {
        if ($hardware->id !== $fixture['hardwareId']) {
            return;
        }

        DB::table('hardwares')->insert([
            'id' => $fixture['hardwareId'],
            'n_code' => $fixture['nCode'],
            'pc_name' => 'RACE_COLLIDING_ROW',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $this->actingAs($fixture['user']);
        $component = Livewire::test('hardware.index')->call('restoreRecord', $fixture['audit']->id);
    } finally {
        $restore();
    }

    // MaryUI's toast() emits an xjs effect: toast({...}). Decode it so the
    // assertion is on the exact operator-facing title, not on session flash.
    $expression = $component->effects['xjs'][0]['expression'] ?? '';
    $toast = json_decode(substr($expression, strlen('toast('), -1), true);

    expect($toast['toast']['title'] ?? null)
        ->toBe('این سخت‌افزار هنوز وجود دارد — از بازگردانی فیلد استفاده کنید.');

    expect(Hardware::where('id', $fixture['hardwareId'])->exists())->toBeFalse();
    expect(HardwareAudit::where('hardware_id', $fixture['hardwareId'])->count())->toBe($auditsBefore);
    expect(HardwareAudit::where('hardware_id', $fixture['hardwareId'])->where('action', 'rollback')->count())->toBe(0);
});

it('rolls the API restore back when the rollback audit write fails', function () {
    $fixture = makeRestoreRaceFixture();

    $auditsBefore = HardwareAudit::where('hardware_id', $fixture['hardwareId'])->count();

    [, , $restore] = captureRestoreRaceListener(HardwareAudit::class);

    HardwareAudit::creating(function (HardwareAudit $audit): void {
        if ($audit->action === 'rollback') {
            throw new RuntimeException('rollback audit write failed');
        }
    });

    $this->withoutExceptionHandling();

    try {
        expect(fn () => $this->withHeaders(['Authorization' => 'Bearer '.$fixture['token']])
            ->postJson("/api/hardware/audits/{$fixture['audit']->id}/restore-record"))
            ->toThrow(RuntimeException::class);
    } finally {
        $restore();
    }

    expect(Hardware::where('id', $fixture['hardwareId'])->exists())->toBeFalse();
    expect(HardwareAudit::where('hardware_id', $fixture['hardwareId'])->count())->toBe($auditsBefore);
});

it('rolls the Livewire restore back when the rollback audit write fails', function () {
    $fixture = makeRestoreRaceFixture();

    $auditsBefore = HardwareAudit::where('hardware_id', $fixture['hardwareId'])->count();

    [, , $restore] = captureRestoreRaceListener(HardwareAudit::class);

    HardwareAudit::creating(function (HardwareAudit $audit): void {
        if ($audit->action === 'rollback') {
            throw new RuntimeException('rollback audit write failed');
        }
    });

    try {
        $this->actingAs($fixture['user']);
        expect(fn () => Livewire::test('hardware.index')->call('restoreRecord', $fixture['audit']->id))
            ->toThrow(RuntimeException::class);
    } finally {
        $restore();
    }

    expect(Hardware::where('id', $fixture['hardwareId'])->exists())->toBeFalse();
    expect(HardwareAudit::where('hardware_id', $fixture['hardwareId'])->count())->toBe($auditsBefore);
});
