<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #736 — `tickets.completed_at` had no index.
 *
 * The column exists since the original `create_tickets_table` migration, but
 * nothing indexed it. Every average-resolution-time aggregate filters on
 * `completed_at IS NOT NULL` and orders by it, so the query degrades into a
 * full scan as the table grows.
 */
class CompletedAtIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function indexesOnTickets(): array
    {
        return array_column(
            DB::select(
                "SELECT indexname FROM pg_indexes
                 WHERE schemaname = current_schema() AND tablename = 'tickets'"
            ),
            'indexname'
        );
    }

    public function test_completed_at_has_an_index(): void
    {
        $this->assertContains('tickets_completed_at_index', $this->indexesOnTickets());
    }

    public function test_the_index_covers_the_completed_at_column(): void
    {
        $columns = DB::selectOne(
            "SELECT indexdef FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename = 'tickets'
               AND indexname = 'tickets_completed_at_index'"
        );

        $this->assertNotNull($columns, 'the index exists');
        $this->assertStringContainsString('(completed_at)', $columns->indexdef);
    }

    public function test_migrating_back_removes_the_index(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $this->assertNotContains('tickets_completed_at_index', $this->indexesOnTickets());

        $this->artisan('migrate')->assertSuccessful();
        $this->assertContains('tickets_completed_at_index', $this->indexesOnTickets());
    }
}
