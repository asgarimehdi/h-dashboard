<?php

namespace Tests\Support\Concerns;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for the #1849 atomic-toggle characterization tests.
 *
 * The same technique as `fakeLockedTodoRow()` in `TodoLivewireTest` (#1634):
 * the `DB` facade is swapped for a manager that answers the `FOR UPDATE`
 * probe with a fabricated row, so a test can simulate "another writer moved
 * the row before our lock" without a second connection (`RefreshDatabase`
 * wraps each test in an open transaction a second connection cannot see).
 *
 * Only `DB::select` is intercepted on purpose — the toggle implementation
 * must use the raw `DB::select('… FOR UPDATE', [...])` shape. A
 * `->lockForUpdate()` query-builder write would reach the real connection and
 * the test would fail, which pins the shape.
 */
trait InteractsWithAtomicToggles
{
    /**
     * Answer every `… FOR UPDATE` select with `$row` (or `[]` when null) and
     * forward everything else to the real connection.
     *
     * @param  object|null  $row  the row the lock reports, or null for "gone"
     */
    protected function fakeLockedRow(?object $row): void
    {
        /** @var DatabaseManager $inner */
        $inner = DB::getFacadeRoot();

        DB::swap(new class(app(), app('db.factory'), $inner, $row) extends DatabaseManager
        {
            public function __construct($app, $factory, private DatabaseManager $inner, private ?object $row)
            {
                parent::__construct($app, $factory);
            }

            public function connection($name = null)
            {
                return $this->inner->connection($name);
            }

            public function select($query, $bindings = [], $useReadPdo = true)
            {
                if (str_contains($query, 'FOR UPDATE')) {
                    return $this->row === null ? [] : [$this->row];
                }

                return $this->inner->connection()->select($query, $bindings, $useReadPdo);
            }
        });
    }

    /**
     * Title of the first Mary toast emitted by the component, if any.
     * Mary toasts are a JS effect (`toast({toast:{…}})`) — not rendered HTML.
     */
    protected function toastTitle(object $component): ?string
    {
        $toast = $this->firstToast($component);

        return isset($toast['title']) ? (string) $toast['title'] : null;
    }

    /**
     * Type of the first Mary toast (`success`, `error`, …), if any.
     */
    protected function toastType(object $component): ?string
    {
        $toast = $this->firstToast($component);

        return isset($toast['type']) ? (string) $toast['type'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstToast(object $component): ?array
    {
        foreach (array_column($component->effects['xjs'] ?? [], 'expression') as $expression) {
            if (! str_starts_with($expression, 'toast(')) {
                continue;
            }

            $inner = json_decode(substr($expression, strlen('toast('), -1), true);

            if (is_array($inner) && isset($inner['toast']) && is_array($inner['toast'])) {
                return $inner['toast'];
            }
        }

        return null;
    }
}
