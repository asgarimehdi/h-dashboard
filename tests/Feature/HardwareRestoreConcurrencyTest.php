<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * #1698: two API restores of the same audit, in two real PHP processes.
 *
 * The explicit-id INSERT is serialised by hardwares_pkey: one process wins,
 * the other blocks then fails with 23505, which is mapped to the existing
 * 422. Assertions are invariant to interleaving so the test is not flaky:
 * exactly one success, no 5xx, one restored row, one rollback audit, two
 * 'created' audits, and the sequence at or above MAX(id).
 *
 * The fixture is committed (no wrapping transaction) so a second process can
 * see it; the class opts out of transaction wrapping and cleans up after
 * itself, exactly like NotificationServiceConcurrencyTest.
 */
class HardwareRestoreConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    public function test_two_concurrent_api_restores_create_one_row_and_one_422(): void
    {
        // Committed fixtures poison SeederGuardTest unless every row this test
        // caused is removed. Remember what the database looked like first.
        $emptyLookupTables = array_values(array_filter(
            ['tahsils', 'estekhdams', 'radifs', 'semats'],
            fn (string $table) => DB::table($table)->doesntExist(),
        ));
        $noUnitsBefore = Unit::query()->doesntExist();
        $permissionExisted = Permission::where('name', 'manage_hardware')->exists();

        $unit = null;
        $person = null;
        $user = null;
        $hardware = null;

        $code = <<<'PHP'
$base = $argv[1];
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$request = Illuminate\Http\Request::create(
    '/api/hardware/audits/'.$argv[2].'/restore-record',
    'POST',
    [], [], [],
    [
        'HTTP_AUTHORIZATION' => 'Bearer '.$argv[3],
        'HTTP_ACCEPT' => 'application/json',
    ]
);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
PHP;

        $connection = config('database.connections.pgsql');

        $env = [
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];

        try {
            $unit = Unit::factory()->create();
            $person = Person::factory()->create(['u_id' => $unit->id]);
            $user = User::factory()->create(['n_code' => $person->n_code]);
            $user->givePermissionTo(Permission::firstOrCreate(['name' => 'manage_hardware']));
            $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

            $hardware = Hardware::create([
                'n_code' => $user->n_code,
                'pc_name' => 'CONCURRENT_RESTORE',
                'type' => 'pc',
                'mac' => 'concurrent-mac',
                'shutdown' => false,
                'mark' => false,
            ]);
            $hardwareId = $hardware->id;

            $audit = HardwareAudit::where('hardware_id', $hardwareId)
                ->where('action', 'created')
                ->firstOrFail();

            // Observer writes the 'deleted' audit; the row itself is gone, so
            // the exists() pre-check in both processes passes.
            $hardware->delete();

            $token = $user->createToken('concurrency')->plainTextToken;

            $command = fn (): array => [
                PHP_BINARY,
                '-r',
                $code,
                base_path(),
                (string) $audit->id,
                $token,
            ];

            // No usleep: the assertions do not depend on which request wins.
            $first = Process::env($env)->path(base_path())->timeout(30)->start($command());
            $second = Process::env($env)->path(base_path())->timeout(30)->start($command());

            $firstResult = $first->wait();
            $secondResult = $second->wait();

            $this->assertSame(0, $firstResult->exitCode(), 'First restore process failed: '.$firstResult->errorOutput());
            $this->assertSame(0, $secondResult->exitCode(), 'Second restore process failed: '.$secondResult->errorOutput());

            $statuses = [(int) trim($firstResult->output()), (int) trim($secondResult->output())];
            sort($statuses);

            $this->assertSame(
                [200, 422],
                $statuses,
                'Expected one restore and one clean refusal, got: '.$firstResult->output().' / '.$secondResult->output()
            );

            $this->assertSame(1, Hardware::where('id', $hardwareId)->count());
            $this->assertSame(
                1,
                HardwareAudit::where('hardware_id', $hardwareId)->where('action', 'rollback')->count()
            );
            $this->assertSame(
                2,
                HardwareAudit::where('hardware_id', $hardwareId)->where('action', 'created')->count()
            );

            $sequence = DB::selectOne('SELECT last_value FROM hardwares_id_seq');
            $maxId = DB::selectOne('SELECT MAX(id) AS m FROM hardwares');

            $this->assertGreaterThanOrEqual(
                (int) $maxId->m,
                (int) $sequence->last_value,
                'The sequence must be at or past the restored id.'
            );
        } finally {
            if ($hardware !== null) {
                Hardware::where('id', $hardware->id)->delete();
                HardwareAudit::where('hardware_id', $hardware->id)->delete();
            }

            if ($user !== null) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->where('tokenable_id', $user->id)
                    ->delete();
                DB::table('model_has_permissions')
                    ->where('model_type', User::class)
                    ->where('model_id', $user->id)
                    ->delete();
                DB::table('user_units')->where('user_id', $user->id)->delete();

                $user->forceDelete();
            }

            if ($person !== null) {
                $person->delete();
            }

            if ($noUnitsBefore && $unit !== null && ! Person::where('u_id', $unit->id)->exists()) {
                $unit->delete();
            }

            if (! $permissionExisted) {
                $permission = Permission::where('name', 'manage_hardware')->first();

                if ($permission !== null) {
                    DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
                    DB::table('model_has_permissions')->where('permission_id', $permission->id)->delete();
                    $permission->delete();
                }

                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            foreach ($emptyLookupTables as $table) {
                DB::table($table)->delete();
            }
        }
    }
}
