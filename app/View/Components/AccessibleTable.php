<?php

namespace App\View\Components;

use Mary\View\Components\Table;

/**
 * `aria-sort` for maryUI's Table (#957, step 2).
 *
 * WHY THIS EXISTS
 * ---------------
 * `<x-table>` is a maryUI component, so its markup lives in vendor/ and cannot be
 * edited from the app. Its sortable header rendered as a bare `<th>` with an
 * `@click` and nothing else: no `aria-sort`, so no screen reader ever said
 * "sorted ascending", and no `tabindex`, so the sort control was unreachable by
 * keyboard at all.
 *
 * WHAT THIS CLASS OWNS
 * --------------------
 * Only `ariaSort()`. The markup that calls it lives in `CspTable`, which extends
 * this class and is registered over the `table` alias; the `CspTable::render()`
 * heredoc adds `aria-sort`/`tabindex`/`@keydown` to the sortable `<th>`. Keeping
 * the helper here preserves the #957 history without a second, unrendered
 * `render()` copy.
 *
 * MAINTENANCE
 * -----------
 * Guarded by `tests/Feature/MaryUiMirrorTest.php` through the `table` contract
 * (`CspTable` ↔ vendor `Table.php`). AccessibilityInfrastructureTest pins the
 * sort payload the click handler still sends.
 *
 * A deployment must clear the compiled Blade cache (`php artisan view:cache`
 * after `view:clear`) or the alias change will not reach already-compiled views.
 */
class AccessibleTable extends Table
{
    /**
     * `aria-sort` for a header: `ascending`, `descending`, or `none` for a
     * sortable column that is not the sorted one.
     *
     * Reads `$sortBy` directly rather than `$this->getSort($header)`, because
     * getSort() returns the direction the NEXT click will apply — the opposite of
     * the current one — which would announce every header backwards.
     */
    public function ariaSort(mixed $header): string
    {
        if (! $this->isSortable($header) || ! $this->isSortedBy($header)) {
            return 'none';
        }

        $direction = is_array($this->sortBy) ? ($this->sortBy['direction'] ?? 'asc') : 'asc';

        return $direction === 'asc' ? 'ascending' : 'descending';
    }
}
