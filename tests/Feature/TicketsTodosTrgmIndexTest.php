<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #871 — ticket and todo search run `LIKE '%term%'` (leading wildcard) on
 * `tickets.subject`, `tickets.ticket_code`, `tickets.content` and `todos.title`.
 *
 * No pg_trgm GIN index was ever built on those columns, so every keystroke (all
 * of these inputs are `wire:model.live`) fell back to a sequential scan whose
 * cost grows with the table — the same gap the persons/hardware/units migrations
 * closed earlier.
 *
 * The governing assertion is the **query plan**, not the response: Postgres
 * returns the same rows with or without the index, so a test that only proves the
 * search answers something proves nothing. `EXPLAIN` is the only thing that can
 * catch a dropped index.
 *
 * `tickets.ticket_code` deliberately gets **no** GIN index: it already has a
 * unique B-tree, which serves exact and prefix lookups, and codes are entered
 * whole or by prefix. A trigram index cannot serve a leading-wildcard match more
 * cheaply than that, so it would only add write amplification to the fastest
 * growing table. That was a closed decision on the issue, overriding the
 * four-index sketch in its original text.
 */
class TicketsTodosTrgmIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Force the planner past a sequential scan: on a 50-row table Postgres
     * prefers a seq scan no matter what indexes exist, so `enable_seqscan = off`
     * is what makes the presence of the trigram indexes observable in a test.
     *
     * @return array<int, string> the plan lines
     */
    private function planFor(string $sql, array $bindings = []): array
    {
        DB::statement('SET LOCAL enable_seqscan = off');

        return array_map(
            'strval',
            array_column(
                DB::select('EXPLAIN '.$sql, $bindings),
                'QUERY PLAN'
            )
        );
    }

    /**
     * @return array<int, string>
     */
    private function indexesOn(string $table): array
    {
        return array_column(
            DB::select(
                'SELECT indexname FROM pg_indexes
                 WHERE schemaname = current_schema() AND tablename = ?',
                [$table]
            ),
            'indexname'
        );
    }

    public static function trgmColumnProvider(): array
    {
        return [
            'tickets.subject' => ['tickets', 'subject', 'tickets_subject_trgm_idx'],
            'tickets.content' => ['tickets', 'content', 'tickets_content_trgm_idx'],
            'todos.title' => ['todos', 'title', 'todos_title_trgm_idx'],
        ];
    }

    #[DataProvider('trgmColumnProvider')]
    public function test_leading_wildcard_search_uses_a_trigram_index(string $table, string $column, string $index): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        $plan = implode("\n", $this->planFor(
            "SELECT id FROM {$table} WHERE {$column} LIKE ?",
            ['%'.'حرفه'.'%']
        ));

        $this->assertStringContainsString(
            'Bitmap Index Scan',
            $plan,
            "a leading-wildcard LIKE on {$table}.{$column} must not fall back to a sequential scan\n{$plan}",
        );
        $this->assertStringContainsString($index, $plan, "the plan should use {$index}");
    }

    #[DataProvider('trgmColumnProvider')]
    public function test_the_index_is_a_gin_trigram_index_on_that_column(string $table, string $column, string $index): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        $this->assertContains($index, $this->indexesOn($table), "{$index} exists on {$table}");

        $definition = DB::selectOne(
            'SELECT indexdef FROM pg_indexes
             WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
            [$table, $index]
        );

        $this->assertNotNull($definition);
        $this->assertStringContainsString('USING gin', $definition->indexdef);
        $this->assertStringContainsString("({$column} gin_trgm_ops)", $definition->indexdef);
    }

    public function test_ticket_code_gets_no_trigram_index(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        $definitions = DB::select(
            "SELECT indexname, indexdef FROM pg_indexes
             WHERE schemaname = current_schema() AND tablename = 'tickets'"
        );

        foreach ($definitions as $index) {
            $this->assertStringNotContainsString(
                'ticket_code gin_trgm_ops',
                $index->indexdef,
                "the unique B-tree serves exact/prefix code lookups; a trigram index on it would only add write cost ({$index->indexname})",
            );
        }

        // The B-tree it relies on must still be there.
        $this->assertNotEmpty(
            array_filter(
                $definitions,
                fn ($index) => str_contains($index->indexname, 'ticket_code')
                    && str_contains($index->indexdef, 'UNIQUE')
            ),
            'tickets.ticket_code still has its unique B-tree index',
        );
    }

    public function test_migrating_back_removes_the_indexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_10_08_000001_add_trgm_indexes_to_tickets_and_todos_search.php',
        ])->assertSuccessful();

        $this->assertNotContains('tickets_subject_trgm_idx', $this->indexesOn('tickets'));
        $this->assertNotContains('tickets_content_trgm_idx', $this->indexesOn('tickets'));
        $this->assertNotContains('todos_title_trgm_idx', $this->indexesOn('todos'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertContains('tickets_subject_trgm_idx', $this->indexesOn('tickets'));
        $this->assertContains('tickets_content_trgm_idx', $this->indexesOn('tickets'));
        $this->assertContains('todos_title_trgm_idx', $this->indexesOn('todos'));
    }
}
