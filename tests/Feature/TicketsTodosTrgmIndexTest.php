<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Traits\PersianNormalizer;
use App\Traits\TicketsQueryScopes;
use Illuminate\Database\Eloquent\Builder;
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
 * The **bare** `tickets.ticket_code` deliberately gets no GIN index: it already
 * has a unique B-tree, which serves exact and prefix lookups, and codes are
 * entered whole or by prefix. A trigram index on the bare column cannot serve a
 * leading-wildcard match more cheaply than that, so it would only add write
 * amplification to the fastest growing table. That remains a closed decision.
 *
 * Issue #1165 adds a second family of indexes on the **folded expression**
 * `PersianNormalizer::foldSeparatorsSql($column)`, not on the bare column — the
 * search compares against the folded expression, so only an expression index can
 * match it. Both ticket pages search `subject` and `ticket_code` inside one OR;
 * PostgreSQL needs an index for *every* OR branch to choose a BitmapOr, so the
 * `ticket_code` expression is indexed too while its bare column stays unindexed.
 * The folded indexes must mirror `charMap()`/`digitMap()` exactly, which is why
 * the parity test below compares the catalog definition against the helper.
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

    /**
     * The trait's helpers are static and are meant to be consumed by a class
     * that `use`s the trait — calling them on the trait itself is deprecated in
     * PHP 8.1+ — so the test reads them through a throwaway consumer.
     */
    private function normalizer(): object
    {
        return new class
        {
            use PersianNormalizer;
        };
    }

    /**
     * The exact search predicate the two ticket pages build, via the production
     * scope rather than a retyped copy. Scope/status predicates are left out on
     * purpose: this test pins that the OR itself is fully indexable, and a
     * highly selective `unit_id` predicate legitimately gives the planner a
     * cheaper path on a small table (the index would then be filtered, not
     * excluded).
     *
     * @param  array<int, string>  $columns
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function ticketSearchSql(string $term, array $columns): array
    {
        $scopes = new class
        {
            use TicketsQueryScopes;

            /** @param Builder<Ticket> $query */
            public function search(Builder $query, ?string $term, array $columns): void
            {
                $this->applyTicketSearch($query, $term, $columns);
            }
        };

        $query = Ticket::query();
        $scopes->search($query, $term, $columns);

        return [$query->toSql(), $query->getBindings()];
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

    public static function foldedIndexProvider(): array
    {
        return [
            'tickets.subject' => ['subject', 'tickets_subject_folded_trgm_idx'],
            'tickets.content' => ['content', 'tickets_content_folded_trgm_idx'],
            'tickets.ticket_code' => ['ticket_code', 'tickets_ticket_code_folded_trgm_idx'],
        ];
    }

    /**
     * The index definition must be the helper's expression, character for
     * character. Comparing against `foldSeparatorsSql()` (rather than checking
     * for a `regexp_replace` token) is what makes this a lockstep guard: change
     * `charMap()`/`digitMap()` without recreating the index and this fails,
     * catching the silently-unused index before it reaches production.
     *
     * PostgreSQL prints the stored expression with `::text` casts and wraps the
     * column reference in parentheses, so both sides drop casts and parens —
     * neither is part of the folding logic.
     */
    #[DataProvider('foldedIndexProvider')]
    public function test_the_folded_expression_index_mirrors_the_helper(string $column, string $index): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        $this->assertContains($index, $this->indexesOn('tickets'), "{$index} exists on tickets");

        $definition = DB::selectOne(
            'SELECT indexdef FROM pg_indexes
             WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
            ['tickets', $index]
        );

        $this->assertNotNull($definition);
        $this->assertStringContainsString('USING gin', $definition->indexdef);
        $this->assertStringContainsString('gin_trgm_ops', $definition->indexdef);

        $normalise = static fn (string $sql): string => str_replace(['::text', '(', ')'], '', $sql);

        $this->assertStringContainsString(
            $normalise($this->normalizer()->foldSeparatorsSql($column)),
            $normalise($definition->indexdef),
            "{$index} must be generated from PersianNormalizer::foldSeparatorsSql('{$column}'), not hand-copied SQL",
        );
    }

    /**
     * Both pages OR `subject`, `ticket_code` and (inbox only) `content` inside a
     * single predicate. PostgreSQL only builds a BitmapOr when every branch is
     * indexable, so this plan is what proves the third index earns its write
     * cost — drop it and the whole OR falls back to a sequential scan.
     */
    public function test_the_inbox_or_query_uses_all_three_folded_indexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        [$sql, $bindings] = $this->ticketSearchSql('شبکه', ['subject', 'ticket_code', 'content']);
        $plan = implode("\n", $this->planFor($sql, $bindings));

        $this->assertStringContainsString('BitmapOr', $plan, "the three-branch inbox OR must be indexable as a whole\n{$plan}");

        foreach ([
            'tickets_subject_folded_trgm_idx',
            'tickets_ticket_code_folded_trgm_idx',
            'tickets_content_folded_trgm_idx',
        ] as $index) {
            $this->assertStringContainsString($index, $plan, "the inbox OR should use {$index}\n{$plan}");
        }
    }

    public function test_the_monitoring_or_query_uses_its_two_folded_indexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        [$sql, $bindings] = $this->ticketSearchSql('شبکه', ['subject', 'ticket_code']);
        $plan = implode("\n", $this->planFor($sql, $bindings));

        $this->assertStringContainsString('BitmapOr', $plan, "the two-branch monitoring OR must be indexable as a whole\n{$plan}");
        $this->assertStringContainsString('tickets_subject_folded_trgm_idx', $plan);
        $this->assertStringContainsString('tickets_ticket_code_folded_trgm_idx', $plan);
        $this->assertStringNotContainsString('tickets_content_folded_trgm_idx', $plan, 'monitoring does not search content');
    }

    public function test_bare_ticket_code_gets_no_trigram_index(): void
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
                "the unique B-tree serves exact/prefix code lookups; a trigram index on the BARE column would only add write cost ({$index->indexname})",
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

    /**
     * The folded migration's round trip: `down()` drops the three expression
     * indexes and leaves the bare-column family untouched, and a fresh `migrate`
     * recreates them. `migrate:rollback --path` names the file explicitly so
     * this never rolls back an unrelated later migration.
     */
    public function test_migrating_back_removes_only_the_folded_indexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        $folded = [
            'tickets_subject_folded_trgm_idx',
            'tickets_content_folded_trgm_idx',
            'tickets_ticket_code_folded_trgm_idx',
        ];

        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_10_20_000001_add_folded_trgm_indexes_to_tickets_search.php',
        ])->assertSuccessful();

        foreach ($folded as $index) {
            $this->assertNotContains($index, $this->indexesOn('tickets'));
        }

        $this->assertContains('tickets_subject_trgm_idx', $this->indexesOn('tickets'));
        $this->assertContains('tickets_content_trgm_idx', $this->indexesOn('tickets'));

        $this->artisan('migrate')->assertSuccessful();

        foreach ($folded as $index) {
            $this->assertContains($index, $this->indexesOn('tickets'));
        }
    }
}
