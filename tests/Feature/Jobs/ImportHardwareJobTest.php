<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ImportHardwareJob;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Support\ImportStatus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class ImportHardwareJobTest extends TestCase
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

    private function hardwareCsv(string $nCode, string $pcName = 'PC-JOB'): string
    {
        return "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n"
            ."{$nCode}\t{$pcName}\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    }

    public function test_job_is_queued_with_the_locked_payload(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);

        Queue::fake();

        $file = UploadedFile::fake()->createWithContent(
            'hardware.csv',
            $this->hardwareCsv($user->n_code, 'PC-QUEUED')
        );

        Livewire::test('hardware.import-hardware.import-hardware')
            ->set('file', $file)
            ->set('compareKey', 'pc_name')
            ->call('importPreview')
            ->call('confirmImport')
            ->assertSet('showPreview', false)
            ->assertSet('importResults', null);

        Queue::assertPushed(ImportHardwareJob::class, function (ImportHardwareJob $job) use ($user, $unit) {
            return $job->compareKey === 'pc_name'
                && $job->selectedActions === ['row_2' => 'create']
                && $job->accessibleUnitIds === [$unit->id]
                && $job->userId === $user->id
                && $job->source === 'import'
                && $job->ip !== null
                && Storage::disk('local')->exists($job->path);
        });
    }

    public function test_job_imports_the_stored_file_and_deletes_it(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        Storage::disk('local')->put('imports/hardware.csv', $this->hardwareCsv($user->n_code, 'PC-JOB'));

        $job = new ImportHardwareJob(
            path: 'imports/hardware.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
        );

        $job->handle();

        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-JOB',
            'n_code' => $user->n_code,
        ]);
        Storage::disk('local')->assertMissing('imports/hardware.csv');
    }

    public function test_job_writes_audit_rows_with_the_dispatch_actor_and_import_source(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        Storage::disk('local')->put('imports/audit.csv', $this->hardwareCsv($user->n_code, 'PC-AUDIT'));

        (new ImportHardwareJob(
            path: 'imports/audit.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
            source: 'import',
            ip: '10.1.2.3',
            userAgent: 'JobTest/1.0',
        ))->handle();

        $audit = HardwareAudit::where('source', 'import')->first();

        $this->assertNotNull($audit, 'Expected an import audit row.');
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('10.1.2.3', $audit->ip_address);
        $this->assertSame('JobTest/1.0', $audit->user_agent);
        $this->assertSame('created', $audit->action);
    }

    public function test_job_with_empty_scope_writes_nothing(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);

        $token = 'empty-token';
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        Storage::disk('local')->put('imports/empty.csv', $this->hardwareCsv($user->n_code, 'PC-NOSCOPE'));

        Log::spy();

        (new ImportHardwareJob(
            path: 'imports/empty.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [],
            userId: $user->id,
            statusToken: $token,
        ))->handle();

        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-NOSCOPE']);
        Storage::disk('local')->assertMissing('imports/empty.csv');

        // #1639: an empty captured scope now goes through the abort path, so
        // the browser is told the import failed and the slot is freed.
        $result = ImportStatus::read($user->id, $token);
        $this->assertNotNull($result);
        $this->assertSame('failed', $result['state']);
        $this->assertTrue(ImportStatus::claim($user->id, 'next-token'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => ($context['reason'] ?? null) === 'scope_empty');
    }

    /**
     * #1639: the permission was held at dispatch time but revoked before the
     * worker ran. The job must refuse the stale snapshot instead of writing.
     */
    public function test_job_aborts_when_the_actor_lost_the_permission(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $token = 'revoked-token';
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        Storage::disk('local')->put('imports/revoked.csv', $this->hardwareCsv($user->n_code, 'PC-REVOKED'));

        Log::spy();

        $user->revokePermissionTo('manage_hardware');

        (new ImportHardwareJob(
            path: 'imports/revoked.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
            statusToken: $token,
        ))->handle();

        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-REVOKED']);
        $this->assertSame(0, HardwareAudit::count());
        Storage::disk('local')->assertMissing('imports/revoked.csv');

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $rowPerson = Person::factory()->create(['u_id' => $unit->id]);

        $token = 'lost-unit-token';
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        Storage::disk('local')->put('imports/lost-unit.csv', $this->hardwareCsv($rowPerson->n_code, 'PC-LOST-UNIT'));

        Log::spy();

        // Detaching the pivot alone is not enough: AccessService falls back to
        // the person's HR unit, so move it too. The test session still holds
        // `current_unit_id = $unit`, which pins the session-free resolution.
        $otherUnit = Unit::factory()->create();
        $user->units()->detach($unit->id);
        $user->person->update(['u_id' => $otherUnit->id]);

        (new ImportHardwareJob(
            path: 'imports/lost-unit.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
            statusToken: $token,
        ))->handle();

        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-LOST-UNIT']);
        $this->assertSame(0, HardwareAudit::count());
        Storage::disk('local')->assertMissing('imports/lost-unit.csv');

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $token = 'deleted-token';
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        Storage::disk('local')->put('imports/deleted.csv', $this->hardwareCsv($user->n_code, 'PC-DELETED'));

        Log::spy();

        $user->delete();

        (new ImportHardwareJob(
            path: 'imports/deleted.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
            statusToken: $token,
        ))->handle();

        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-DELETED']);
        $this->assertSame(0, HardwareAudit::count());
        Storage::disk('local')->assertMissing('imports/deleted.csv');

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $secondUnit = Unit::factory()->create();
        $user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

        $rowPerson = Person::factory()->create(['u_id' => $secondUnit->id]);

        $token = 'narrowed-token';
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        Storage::disk('local')->put('imports/narrowed.csv', $this->hardwareCsv($rowPerson->n_code, 'PC-NARROWED'));

        Log::spy();

        $user->units()->detach($secondUnit->id);

        (new ImportHardwareJob(
            path: 'imports/narrowed.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id, $secondUnit->id],
            userId: $user->id,
            statusToken: $token,
        ))->handle();

        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-NARROWED']);
        $this->assertSame(0, HardwareAudit::count());
        Storage::disk('local')->assertMissing('imports/narrowed.csv');

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $secondUnit = Unit::factory()->create();
        $user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

        Storage::disk('local')->put('imports/broader.csv', $this->hardwareCsv($user->n_code, 'PC-BROADER'));

        (new ImportHardwareJob(
            path: 'imports/broader.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
        ))->handle();

        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-BROADER', 'n_code' => $user->n_code]);
        Storage::disk('local')->assertMissing('imports/broader.csv');
    }

    /**
     * #1639: `userId === null` fails closed — there is no actor to authorize,
     * so nothing is written and there is no claim/token to publish to.
     */
    public function test_job_aborts_without_an_actor(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        Storage::disk('local')->put('imports/no-actor.csv', $this->hardwareCsv($user->n_code, 'PC-NO-ACTOR'));

        Log::spy();

        (new ImportHardwareJob(
            path: 'imports/no-actor.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: null,
        ))->handle();

        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-NO-ACTOR']);
        $this->assertSame(0, HardwareAudit::count());
        Storage::disk('local')->assertMissing('imports/no-actor.csv');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => ($context['reason'] ?? null) === 'user_missing');
    }

    public function test_job_does_not_write_a_row_belonging_to_another_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $otherUnit = Unit::factory()->create();
        $otherPerson = Person::factory()->create(['u_id' => $otherUnit->id]);

        Storage::disk('local')->put(
            'imports/other.csv',
            $this->hardwareCsv($otherPerson->n_code, 'PC-OTHER-UNIT')
        );

        (new ImportHardwareJob(
            path: 'imports/other.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
        ))->handle();

        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-OTHER-UNIT']);
    }

    public function test_job_declares_sync_safe_queue_contract(): void
    {
        $job = new ImportHardwareJob('imports/x.csv', 'pc_name', [], []);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(1, $job->tries, 'A partial import must not be replayed.');
        $this->assertGreaterThanOrEqual(300, $job->timeout);
    }

    public function test_failed_logs_the_error(): void
    {
        Log::spy();

        $job = new ImportHardwareJob('imports/x.csv', 'pc_name', [], [], userId: 7);
        $job->failed(new \RuntimeException('boom'));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'ImportHardwareJob')
                && ($context['user_id'] ?? null) === 7
                && ($context['path'] ?? null) === 'imports/x.csv');
    }

    /**
     * #1194: the job never retries (`$tries = 1`), so a failed run leaves the
     * stored upload behind forever unless `failed()` deletes it. The UI has
     * already told the user the import is running, so nothing else would ever
     * clean it up.
     */
    public function test_failed_deletes_the_stored_upload(): void
    {
        Storage::disk('local')->put('imports/leftover.csv', $this->hardwareCsv('1234567890'));

        $job = new ImportHardwareJob('imports/leftover.csv', 'pc_name', [], []);
        $job->failed(new \RuntimeException('boom'));

        Storage::disk('local')->assertMissing('imports/leftover.csv');
    }

    public function test_job_imports_update_rows_as_well_as_creates(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        Hardware::create([
            'n_code' => $user->n_code,
            'pc_name' => 'PC-EXISTING',
            'type' => 'pc',
            'os' => 'Windows 10',
        ]);

        Storage::disk('local')->put(
            'imports/update.csv',
            $this->hardwareCsv($user->n_code, 'PC-EXISTING')
        );

        (new ImportHardwareJob(
            path: 'imports/update.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'update'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
        ))->handle();

        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-EXISTING', 'os' => 'Windows 11']);
        $this->assertSame(1, HardwareAudit::where('source', 'import')->where('action', 'updated')->count());
    }

    /**
     * #1194: the browser learns the import finished through the cache — the
     * job publishes the counts and frees the user's import slot.
     */
    public function test_job_publishes_the_result_and_frees_the_import_slot(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $token = 'test-token';

        // The dispatcher took the slot before queueing the job.
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        Storage::disk('local')->put('imports/publish.csv', $this->hardwareCsv($user->n_code, 'PC-PUBLISH'));

        (new ImportHardwareJob(
            path: 'imports/publish.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [$unit->id],
            userId: $user->id,
            statusToken: $token,
        ))->handle();

        $result = ImportStatus::read($user->id, $token);

        $this->assertNotNull($result, 'Expected the job to publish its result.');
        $this->assertSame('done', $result['state']);
        $this->assertSame(1, $result['created']);

        // The slot is free again, so the user can import once more.
        $this->assertTrue(ImportStatus::claim($user->id, 'next-token'));
    }

    /**
     * #1194: a failed import must also free the slot and tell the browser,
     * otherwise the user is locked out of importing for the whole TTL.
     */
    public function test_failed_job_publishes_the_failure_and_frees_the_import_slot(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);

        $token = 'failed-token';
        $this->assertTrue(ImportStatus::claim($user->id, $token));

        $job = new ImportHardwareJob(
            path: 'imports/gone.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [],
            userId: $user->id,
            statusToken: $token,
        );

        $job->failed(new \RuntimeException('boom'));

        $result = ImportStatus::read($user->id, $token);

        $this->assertNotNull($result, 'Expected the failed job to publish a failure.');
        $this->assertSame('failed', $result['state']);
        $this->assertSame('boom', $result['error']);

        $this->assertTrue(ImportStatus::claim($user->id, 'next-token'));
    }
}
