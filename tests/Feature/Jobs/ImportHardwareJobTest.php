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

        Storage::disk('local')->put('imports/empty.csv', $this->hardwareCsv($user->n_code, 'PC-NOSCOPE'));

        (new ImportHardwareJob(
            path: 'imports/empty.csv',
            compareKey: 'pc_name',
            selectedActions: ['row_2' => 'create'],
            accessibleUnitIds: [],
            userId: $user->id,
        ))->handle();

        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-NOSCOPE']);
        Storage::disk('local')->assertMissing('imports/empty.csv');
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
