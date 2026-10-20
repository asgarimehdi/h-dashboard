<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ImportPersonsJob;
use App\Models\Unit;
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
            ->set('importResults', [
                'preview' => [], 'created' => 1, 'updated' => 0, 'skipped' => 0,
                'errors' => [], 'changes' => [],
            ])
            ->set('previewData', [
                ['n_code' => '9000000099', 'status' => 'create'],
            ])
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
