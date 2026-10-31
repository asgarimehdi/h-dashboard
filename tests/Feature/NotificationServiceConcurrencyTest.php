<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Reproduces failure mode 2 of #1286 (two concurrent senders) with two real
 * PHP processes, because sequential calls cannot show the race: both INSERTs
 * succeed today unless the unique (user_id, dedupe_key) index is what wins.
 *
 * The fixture is committed (no wrapping transaction), so the class opts out of
 * transaction wrapping and cleans up after itself.
 */
class NotificationServiceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    public function test_two_concurrent_keyed_sends_create_one_row(): void
    {
        // The fixture is committed, so remember what the database looked like
        // before the factories ran: only rows this test caused are removed in
        // cleanup (a leftover lookup row poisons SeederGuardTest, which resets
        // sequences to 1 and expects an empty reference table).
        $emptyLookupTables = array_values(array_filter(
            ['tahsils', 'estekhdams', 'radifs', 'semats'],
            fn (string $table) => DB::table($table)->doesntExist(),
        ));
        $noUnitsBefore = Unit::query()->doesntExist();

        $user = null;

        $code = <<<'PHP'
$base = $argv[1];
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Illuminate\Support\Facades\DB::transaction(function () use ($argv) {
    App\Services\NotificationService::send(
        userId: (int) $argv[2],
        type: 'concurrency',
        title: 'race',
        dedupeKey: $argv[3],
    );

    usleep((int) $argv[4]);
});
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
        ];

        $command = fn (int $userId, int $hold) => [
            PHP_BINARY,
            '-r',
            $code,
            base_path(),
            (string) $userId,
            'race-key',
            (string) $hold,
        ];

        try {
            $user = User::factory()->create();

            // A inserts and holds its transaction open; B's INSERT on the
            // unique index blocks until A commits, then fails with 23505.
            $first = Process::env($env)->path(base_path())->timeout(15)->start($command($user->id, 1_500_000));

            usleep(300_000);

            $second = Process::env($env)->path(base_path())->timeout(15)->start($command($user->id, 0));

            $firstResult = $first->wait();
            $secondResult = $second->wait();

            $this->assertSame(0, $firstResult->exitCode(), 'First sender failed: '.$firstResult->errorOutput());
            $this->assertSame(0, $secondResult->exitCode(), 'Second sender failed: '.$secondResult->errorOutput());

            $this->assertSame(
                1,
                Notification::where('user_id', $user->id)->where('dedupe_key', 'race-key')->count(),
            );
        } finally {
            if ($user !== null) {
                Notification::where('user_id', $user->id)->delete();

                $nCode = $user->n_code;
                $user->forceDelete();

                $person = Person::where('n_code', $nCode)->first();
                $unitId = $person?->u_id;
                $person?->delete();

                if ($noUnitsBefore && $unitId && ! Person::where('u_id', $unitId)->exists()) {
                    Unit::where('id', $unitId)->delete();
                }
            }

            foreach ($emptyLookupTables as $table) {
                DB::table($table)->delete();
            }
        }
    }
}
