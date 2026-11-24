<?php

namespace App\Traits;

/**
 * Keeps the client-settable `perPage` Livewire property inside the same
 * 1..100 window the API enforces through `App\Rules\PerPage` (#894/#909).
 *
 * Why a clamp and not a 422: the web UI has no validation channel for a
 * property write, and the repo's reference behaviour (`disease/index`) already
 * clamps silently. `paginate()` cannot be trusted to police itself — on this
 * Laravel version a `0` silently falls back to `Model::$perPage` (15, not the
 * component's own default) and a negative value is dropped by
 * `Query\Builder::limit()` (`if ($value >= 0)`), returning the whole table.
 */
trait ClampsPageSize
{
    /** Same ceiling as `App\Rules\PerPage`'s default (#894/#909). */
    private const MAX_PER_PAGE = 100;

    protected function pageSize(): int
    {
        return max(1, min(self::MAX_PER_PAGE, $this->perPage));
    }

    /**
     * Livewire hook: normalise the state (so the MaryUI per-page select always
     * matches a real option) and start a new result set (issue #1024's
     * contract, already implemented by `disease/index`).
     *
     * `mixed` on purpose — the select posts strings and the property is int.
     */
    public function updatedPerPage(mixed $value = null): void
    {
        $this->perPage = $this->pageSize();
        $this->resetPage();
    }
}
