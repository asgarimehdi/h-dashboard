<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ImportPersonsJob;
use App\Models\Person;
use App\Models\Unit;
use App\Support\ImportStatus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class ImportPersonsJobTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
        Storage::fake('local');
    }

    private function personsCsv(string $nCode, int $unitId, string $firstName = 'علی'): string
    {
        return "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n"
            ."{$nCode}\t{$firstName}\tرضایی\t1\t1\t1\t1\t{$unitId}\n";
    }

    public function test_job_is_queued_with_the_locked_payload(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        Queue::fake();

        $file = UploadedFile::fake()->createWithContent(
            'persons.csv',
            $this->personsCsv('9000000099', $unit->id)
        );

        Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            ->call('confirmImport')
            ->assertSet('showPreview', false)
            ->assertSet('importResults', null);

        Queue::assertPushed(ImportPersonsJob::class, function (ImportPersonsJob $job) use ($user, $unit) {
            return $job->compareKey === 'n_code'
                && $job->selectedActions === ['row_2' => 'create']
                && $job->accessibleUnitIds === [$unit->id]
                && $job->userId === $user->id
                && Storage::disk('local')->exists($job->path);
        });
    }

    public function test_job_imports_the_stored_file_and_deletes_it(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);

        Storage::disk('local')->put('imports/persons.csv', $this->personsCsv('9000000100', $unit->id));

        (new ImportPersonsJob(
            path: 'imports/persons.csv',
            compareKey: 'n_code',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
        ))->handle();

        $this->assertDatabaseHas('persons', ['n_code' => '9000000100', 'u_id' => $unit->id]);
        Storage::disk('local')->assertMissing('imports/persons.csv');
    }

    public function test_job_does_not_write_persons_outside_the_captured_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);

        $otherUnit = Unit::factory()->create();

        Storage::disk('local')->put('imports/other-persons.csv', $this->personsCsv('9000000101', $otherUnit->id));

        (new ImportPersonsJob(
            path: 'imports/other-persons.csv',
            compareKey: 'n_code',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
        ))->handle();

        $this->assertDatabaseMissing('persons', ['n_code' => '9000000101']);
    }

    /**
     * #1639: the permission was held at dispatch time but revoked before the
     * worker ran. The job must refuse the stale snapshot instead of writing.
     */
    public function test_job_aborts_when_the_actor_lost_the_permission(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);

        $token = 'revoked-token';
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        Storage::disk('local')->put('imports/revoked-persons.csv', $this->personsCsv('9000000110', $unit->id));

        Log::spy();

        $user->revokePermissionTo('manage_personnel');

        (new ImportPersonsJob(
            path: 'imports/revoked-persons.csv',
            compareKey: 'n_code',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
            statusToken: $token,
        ))->handle();

        $this->assertDatabaseMissing('persons', ['n_code' => '9000000110']);
        Storage::disk('local')->assertMissing('imports/revoked-persons.csv');

        $result = ImportStatus::read($user->id, $token);
        $this->assertNotNull($result);
        $this->assertSame('failed', $result['state']);
        $this->assertStringContainsString('مجوز', (string) $result['error']);

        $this->assertTrue(ImportStatus::claim($user->id, 'next-token'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => ($context['reason'] ?? null) === 'permission_revoked');
    }

    /**
     * #1639: the actor lost the unit between dispatch and execution. The sheet
     * row belongs to that unit, so only the re-check can stop the write.
     */
    public function test_job_aborts_when_the_actor_lost_the_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);

        $token = 'lost-unit-token';
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        Storage::disk('local')->put('imports/lost-unit-persons.csv', $this->personsCsv('9000000111', $unit->id));

        Log::spy();

        // Detaching the pivot alone is not enough: AccessService falls back to
        // the person's HR unit, so move it too. The test session still holds
        // `current_unit_id = $unit`, which pins the session-free resolution.
        $otherUnit = Unit::factory()->create();
        $user->units()->detach($unit->id);
        $user->person->update(['u_id' => $otherUnit->id]);

        (new ImportPersonsJob(
            path: 'imports/lost-unit-persons.csv',
            compareKey: 'n_code',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
            statusToken: $token,
        ))->handle();

        $this->assertDatabaseMissing('persons', ['n_code' => '9000000111']);
        Storage::disk('local')->assertMissing('imports/lost-unit-persons.csv');

        $result = ImportStatus::read($user->id, $token);
        $this->assertNotNull($result);
        $this->assertSame('failed', $result['state']);
        $this->assertTrue(ImportStatus::claim($user->id, 'next-token'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => ($context['reason'] ?? null) === 'scope_empty');
    }

    /**
     * #1639: a deleted actor must not keep writing through a queued job.
     */
    public function test_job_aborts_when_the_actor_was_deleted(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);

        $token = 'deleted-token';
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        Storage::disk('local')->put('imports/deleted-persons.csv', $this->personsCsv('9000000112', $unit->id));

        Log::spy();

        $user->delete();

        (new ImportPersonsJob(
            path: 'imports/deleted-persons.csv',
            compareKey: 'n_code',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
            statusToken: $token,
        ))->handle();

        $this->assertDatabaseMissing('persons', ['n_code' => '9000000112']);
        Storage::disk('local')->assertMissing('imports/deleted-persons.csv');

        $result = ImportStatus::read($user->id, $token);
        $this->assertNotNull($result);
        $this->assertSame('failed', $result['state']);
        $this->assertTrue(ImportStatus::claim($user->id, 'next-token'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => ($context['reason'] ?? null) === 'user_missing');
    }

    /**
     * #1639: the captured scope was confirmed for two units, one is removed
     * before the worker runs. A partial write would silently skip half of the
     * confirmed diff, so the job refuses the whole sheet.
     */
    public function test_job_aborts_when_the_captured_scope_is_narrowed(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);

        $secondUnit = Unit::factory()->create();
        $user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

        $token = 'narrowed-token';
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        Storage::disk('local')->put('imports/narrowed-persons.csv', $this->personsCsv('9000000113', $secondUnit->id));

        Log::spy();

        $user->units()->detach($secondUnit->id);

        (new ImportPersonsJob(
            path: 'imports/narrowed-persons.csv',
            compareKey: 'n_code',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id, $secondUnit->id],
            userId: $user->id,
            statusToken: $token,
        ))->handle();

        $this->assertDatabaseMissing('persons', ['n_code' => '9000000113']);
        Storage::disk('local')->assertMissing('imports/narrowed-persons.csv');

        $result = ImportStatus::read($user->id, $token);
        $this->assertNotNull($result);
        $this->assertSame('failed', $result['state']);
        $this->assertTrue(ImportStatus::claim($user->id, 'next-token'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => ($context['reason'] ?? null) === 'scope_narrowed');
    }

    /**
     * #1639: the captured scope is only ever intersected, never replaced — a
     * user whose current scope is broader than the snapshot still runs for the
     * units the sheet was confirmed for.
     */
    public function test_job_still_runs_when_the_actor_scope_is_broader_than_the_capture(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);

        $secondUnit = Unit::factory()->create();
        $user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

        Storage::disk('local')->put('imports/broader-persons.csv', $this->personsCsv('9000000114', $unit->id));

        (new ImportPersonsJob(
            path: 'imports/broader-persons.csv',
            compareKey: 'n_code',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
        ))->handle();

        $this->assertDatabaseHas('persons', ['n_code' => '9000000114', 'u_id' => $unit->id]);
        Storage::disk('local')->assertMissing('imports/broader-persons.csv');
    }

    /**
     * #1639: `userId === null` fails closed — there is no actor to authorize,
     * so nothing is written and there is no claim/token to publish to.
     */
    public function test_job_aborts_without_an_actor(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);

        Storage::disk('local')->put('imports/no-actor-persons.csv', $this->personsCsv('9000000115', $unit->id));

        Log::spy();

        (new ImportPersonsJob(
            path: 'imports/no-actor-persons.csv',
            compareKey: 'n_code',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: null,
        ))->handle();

        $this->assertDatabaseMissing('persons', ['n_code' => '9000000115']);
        Storage::disk('local')->assertMissing('imports/no-actor-persons.csv');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => ($context['reason'] ?? null) === 'user_missing');
    }

    public function test_job_declares_sync_safe_queue_contract(): void
    {
        $job = new ImportPersonsJob('imports/x.csv', 'n_code', [], []);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertGreaterThanOrEqual(300, $job->timeout);
    }

    public function test_failed_logs_the_error(): void
    {
        Log::spy();

        $job = new ImportPersonsJob('imports/x.csv', 'n_code', [], [], userId: 9);
        $job->failed(new \RuntimeException('boom'));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'ImportPersonsJob')
                && ($context['user_id'] ?? null) === 9);
    }

    /**
     * #1697: a non-numeric lookup id used to parse to `0`, pass the preview,
     * and then abort the whole queued import with a foreign-key violation
     * (`tries = 1`, so nothing else was ever written).
     */
    public function test_job_imports_the_rest_of_the_sheet_when_one_lookup_id_is_invalid(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);

        Storage::disk('local')->put('imports/mixed-persons.csv',
            "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n"
            ."9000000120\tعلی\tرضایی\tabc\t1\t1\t1\t{$unit->id}\n"
            ."9000000121\tحسن\tکریمی\t1\t1\t1\t1\t{$unit->id}\n"
        );

        (new ImportPersonsJob(
            path: 'imports/mixed-persons.csv',
            compareKey: 'n_code',
            selectedActions: ['row_2' => 'create', 'row_3' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
        ))->handle();

        $this->assertDatabaseHas('persons', ['n_code' => '9000000121', 'u_id' => $unit->id, 't_id' => 1]);
        $this->assertDatabaseMissing('persons', ['n_code' => '9000000120']);
        $this->assertSame(
            1,
            Person::query()->whereIn('n_code', ['9000000120', '9000000121'])->count()
        );
        Storage::disk('local')->assertMissing('imports/mixed-persons.csv');
    }

    public function test_job_bumps_hr_cache_namespaces_exactly_once(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        Cache::flush();

        Storage::disk('local')->put('imports/cache.csv', $this->personsCsv('9000000102', $unit->id));

        (new ImportPersonsJob(
            path: 'imports/cache.csv',
            compareKey: 'n_code',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
        ))->handle();

        $this->assertSame(1, Cache::get('hr_stats_version'));
        $this->assertSame(1, Cache::get('dashboard_version'));
        $this->assertSame(1, Cache::get('maps_version'));
        $this->assertSame(1, Cache::get('gis_version'));
    }
}
