<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #1525 — three historical migrations revert a nullable column back to
 * NOT NULL in `down()`, and each of them had only a comment standing between an
 * operator and irreversible data loss:
 *
 *   - `tickets.user_id`      — system tickets from `maintenance:generate-due`
 *                              are deliberately ownerless;
 *   - `activity_logs.user_id` — rows written with `logAs(null, …)` after a
 *                              restore replaced the acting user;
 *   - `persons.u_id`          — rows left behind by `ON DELETE SET NULL`.
 *
 * A rollback to NOT NULL with NULL rows present fails inside PostgreSQL, but
 * a `down()` that "declines" by returning `false` is worse than failing: a
 * `: void` signature makes that a fatal compile error, and lifting the
 * signature makes the Migrator ignore the value and delete the migration
 * record anyway (`Migrator::runDown()`), leaving the running schema and the
 * `migrations` table disagreeing forever.
 *
 * So the guard is an exception, thrown before the first `Schema::table(`:
 * PostgreSQL rolls the statement back, the migration record survives, and the
 * next `migrate` still considers the migration applied — schema and state stay
 * consistent. These pins are what make that contract load-bearing:
 *
 *   - the rollback FAILS (not silently no-ops);
 *   - the `migrations` row survives (this is the assertion `return false` dies
 *     on);
 *   - the column is untouched (`is_nullable = YES`);
 *   - for `activity_logs`, the FK is still `confdeltype = 'n'`, i.e. `down()`
 *     did not run even halfway (atomicity).
 */
class MigrationRollbackGuardsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * migration basename => [table, column, foreign-key constraint].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const GUARDED = [
        '2026_08_29_000005_make_tickets_user_id_nullable' => ['tickets', 'user_id', 'tickets_user_fk'],
        '2026_09_03_002128_change_activity_logs_user_fk_to_set_null' => ['activity_logs', 'user_id', 'activity_logs_user_id_foreign'],
        '2026_09_03_002219_add_u_id_fk_to_persons_table' => ['persons', 'u_id', 'persons_u_id_foreign_units'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The guard assertions read PostgreSQL catalogs (information_schema, pg_constraint).');
        }
    }

    // --- tickets -----------------------------------------------------------

    public function test_tickets_rollback_fails_while_an_ownerless_system_ticket_exists(): void
    {
        $unit = Unit::factory()->create();
        $ticket = Ticket::factory()->create(['unit_id' => $unit->id]);

        $this->assertNull($ticket->user_id, 'TicketFactory must keep producing ownerless system tickets');

        $abort = $this->rollbackAbortedBy('2026_08_29_000005_make_tickets_user_id_nullable');

        $this->assertStringContainsString('has 1 NULL row(s)', $abort->getMessage(), 'The guard message must report the blocking row count');
        $this->assertStringContainsString('Reassign them', $abort->getMessage(), 'The guard message must be actionable');

        $this->assertDatabaseHas('migrations', ['migration' => '2026_08_29_000005_make_tickets_user_id_nullable']);
        $this->assertSame('YES', $this->isNullable('tickets', 'user_id'));
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }

    public function test_tickets_rollback_succeeds_once_every_ticket_has_an_owner(): void
    {
        $unit = Unit::factory()->create();
        $user = User::factory()->create();

        Ticket::factory()->create(['user_id' => $user->id, 'unit_id' => $unit->id]);

        $this->rollback('2026_08_29_000005_make_tickets_user_id_nullable')->assertSuccessful();

        $this->assertDatabaseMissing('migrations', ['migration' => '2026_08_29_000005_make_tickets_user_id_nullable']);
        $this->assertSame('NO', $this->isNullable('tickets', 'user_id'));
        $this->assertSame('c', $this->confDelType('tickets_user_fk'));

        $this->restoreSchema();
        $this->assertSame('YES', $this->isNullable('tickets', 'user_id'));
    }

    // --- activity_logs -----------------------------------------------------

    public function test_activity_logs_rollback_fails_while_an_actorless_log_exists(): void
    {
        // The real path: `resources/views/livewire/backup/index.blade.php` logs
        // a restore with no actor because the restore may have replaced them.
        ActivityLogService::logAs(null, 'restore', null, 'بازگردانی تمام جدول‌ها انجام شد.');

        $abort = $this->rollbackAbortedBy('2026_09_03_002128_change_activity_logs_user_fk_to_set_null');

        $this->assertStringContainsString('1 NULL row(s)', $abort->getMessage());

        $this->assertDatabaseHas('migrations', ['migration' => '2026_09_03_002128_change_activity_logs_user_fk_to_set_null']);
        $this->assertSame('YES', $this->isNullable('activity_logs', 'user_id'));
        // 'n' = ON DELETE SET NULL, the shape `up()` installed. If `down()` had
        // run even partly, the FK would already be back to 'c' (CASCADE).
        $this->assertSame('n', $this->confDelType('activity_logs_user_id_foreign'));
    }

    public function test_activity_logs_rollback_succeeds_and_restores_the_cascading_fk(): void
    {
        $user = User::factory()->create();
        ActivityLogService::logAs($user->id, 'login', null, 'ورود به سامانه');

        $this->rollback('2026_09_03_002128_change_activity_logs_user_fk_to_set_null')->assertSuccessful();

        $this->assertDatabaseMissing('migrations', ['migration' => '2026_09_03_002128_change_activity_logs_user_fk_to_set_null']);
        $this->assertSame('NO', $this->isNullable('activity_logs', 'user_id'));
        $this->assertSame('c', $this->confDelType('activity_logs_user_id_foreign'));

        $this->restoreSchema();
        $this->assertSame('YES', $this->isNullable('activity_logs', 'user_id'));
        $this->assertSame('n', $this->confDelType('activity_logs_user_id_foreign'));
    }

    // --- persons -----------------------------------------------------------

    public function test_persons_rollback_fails_while_a_person_has_no_unit(): void
    {
        // The live path: `persons.u_id` is `ON DELETE SET NULL`, so deleting a
        // unit leaves its people unit-less. (The import path rejects blank
        // `u_id` — `PersonImport` records the row as rejected instead.)
        $unit = Unit::factory()->create();
        $person = Person::factory()->create(['u_id' => $unit->id]);

        $unit->delete();

        $this->assertNull($person->fresh()->u_id);

        $abort = $this->rollbackAbortedBy('2026_09_03_002219_add_u_id_fk_to_persons_table');

        $this->assertStringContainsString('1 NULL row(s)', $abort->getMessage());

        $this->assertDatabaseHas('migrations', ['migration' => '2026_09_03_002219_add_u_id_fk_to_persons_table']);
        $this->assertSame('YES', $this->isNullable('persons', 'u_id'));
        $this->assertNull($person->fresh()->u_id);
    }

    public function test_persons_rollback_succeeds_once_every_person_has_a_unit(): void
    {
        $unit = Unit::factory()->create();
        Person::factory()->create(['u_id' => $unit->id]);

        $this->rollback('2026_09_03_002219_add_u_id_fk_to_persons_table')->assertSuccessful();

        $this->assertDatabaseMissing('migrations', ['migration' => '2026_09_03_002219_add_u_id_fk_to_persons_table']);
        $this->assertSame('NO', $this->isNullable('persons', 'u_id'));

        $this->restoreSchema();
        $this->assertSame('YES', $this->isNullable('persons', 'u_id'));
    }

    // --- static guards -----------------------------------------------------

    /**
     * The next migration of this family must not arrive without a guard. The
     * trap: `grep nullable(false)` finds only two of the three files, because
     * `2026_09_03_002128` reaches NOT NULL by REMOVING `->nullable()` — so the
     * set is keyed on `->change()`, the common denominator.
     */
    public function test_exactly_three_migrations_revert_a_column_with_change(): void
    {
        $withChange = [];

        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (str_contains(file_get_contents($file), '->change()')) {
                $withChange[] = basename($file, '.php');
            }
        }

        sort($withChange);

        $expected = array_keys(self::GUARDED);
        sort($expected);

        $this->assertSame(
            $expected,
            $withChange,
            'A new migration that reverts a column with ->change() needs its own rollback guard and a pin in this test.'
        );
    }

    public function test_no_migration_down_method_returns_a_value(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/*.php')) as $file) {
            $down = $this->downMethod($file);

            if ($down['signature'] !== 'void' || $down['returns']) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'down() must stay `: void` and must not return a value: `return false` is a fatal error under `: void`, '
            .'and a value is ignored by Migrator::runDown(), which deletes the migration record anyway.'
        );
    }

    public function test_each_guard_counts_nulls_before_touching_the_schema(): void
    {
        foreach (self::GUARDED as $basename => [$table, $column]) {
            $body = $this->downMethod(database_path("migrations/{$basename}.php"))['body'];

            $count = strpos($body, "DB::table('{$table}')->whereNull('{$column}')->count()");
            $throw = strpos($body, 'throw new RuntimeException');
            $schema = strpos($body, 'Schema::table(');

            $this->assertNotFalse($count, "{$basename}: down() must count NULL {$table}.{$column} rows");
            $this->assertNotFalse($throw, "{$basename}: down() must throw RuntimeException");
            $this->assertNotFalse($schema, "{$basename}: down() must still revert the column");
            $this->assertLessThan($schema, $throw, "{$basename}: the guard must precede the first Schema::table()");
            $this->assertStringNotContainsString('->exists()', $body, "{$basename}: count(), not exists(), so the message can be actionable");

            $dropForeign = strpos($body, 'dropForeign');

            if ($dropForeign !== false) {
                $this->assertLessThan($dropForeign, $throw, "{$basename}: the guard must precede dropForeign() too");
            }
        }
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Roll back exactly one migration file, never a step of the last batch.
     */
    private function rollback(string $basename): PendingCommand
    {
        return $this->artisan('migrate:rollback', [
            '--path' => "database/migrations/{$basename}.php",
        ]);
    }

    /**
     * Re-apply whatever the success-path test rolled back, then prove the
     * column is nullable again — the round trip the next `migrate` performs.
     */
    private function restoreSchema(): void
    {
        $this->artisan('migrate')->assertSuccessful();
    }

    /**
     * Run the rollback for real and return the exception that aborted it.
     *
     * `assertFailed()` is not usable here: Laravel's console application
     * disables Symfony's exception catching
     * (`Application::setCatchExceptions(false)`, `vendor/…/Console/Application.php:76`),
     * so a throwing `down()` reaches the test as the exception itself instead
     * of a `PendingCommand` exit code. What the operator sees is still a
     * non-zero exit; what this returns is the message they read.
     */
    private function rollbackAbortedBy(string $basename): RuntimeException
    {
        try {
            $this->rollback($basename)->run();
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail("Rolling back {$basename} was expected to abort, but it completed.");
    }

    private function isNullable(string $table, string $column): string
    {
        $row = DB::selectOne(
            'SELECT is_nullable FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        $this->assertNotNull($row, "{$table}.{$column} must exist");

        return $row->is_nullable;
    }

    private function confDelType(string $constraint): string
    {
        $row = DB::selectOne(
            "SELECT confdeltype FROM pg_constraint
              WHERE conname = ? AND contype = 'f' AND connamespace = current_schema()::regnamespace",
            [$constraint]
        );

        $this->assertNotNull($row, "Foreign key {$constraint} must exist");

        return $row->confdeltype;
    }

    /**
     * Read `down()`'s real signature and body from the migration itself (no
     * regex over comments), plus whether it returns a value.
     *
     * @return array{body: string, returns: bool, signature: string}
     */
    private function downMethod(string $path): array
    {
        $migration = require $path;

        $method = new ReflectionMethod($migration, 'down');
        $lines = file($path);

        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $returnType = $method->getReturnType();

        return [
            'body' => $body,
            'returns' => $this->returnsAValue($body),
            'signature' => $returnType instanceof ReflectionNamedType ? $returnType->getName() : 'none',
        ];
    }

    /**
     * A bare `return;` (early exit) is fine; `return <value>` is not.
     */
    private function returnsAValue(string $snippet): bool
    {
        $tokens = token_get_all('<?php '.$snippet);
        $count = count($tokens);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_RETURN) {
                continue;
            }

            $next = $index + 1;

            while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $next++;
            }

            if (($tokens[$next] ?? null) !== ';') {
                return true;
            }
        }

        return false;
    }
}
