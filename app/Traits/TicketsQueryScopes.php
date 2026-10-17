<?php

namespace App\Traits;

use App\Models\Ticket;
use App\Support\JalaliDateRange;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared ticket-list filters (#1055).
 *
 * `tickets.inbox` and `tickets.monitoring` carried the status / Jalali
 * date-range / search filter block line-for-line in each component, so the
 * two pages could drift apart silently. The caller still owns the base query
 * (organizational scope + the inbox's received/sent mode); these helpers only
 * append the filter predicates, and preserve the previous behaviour exactly.
 */
trait TicketsQueryScopes
{
    /**
     * `pending` is the open work (created or forwarded); `all` adds no status
     * predicate; any other value is matched exactly.
     *
     * @param  Builder<Ticket>  $query
     */
    protected function applyTicketStatusFilter(Builder $query, string $statusFilter): void
    {
        if ($statusFilter === 'pending') {
            $query->whereIn('status', ['created', 'forwarded']);
        } elseif ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }
    }

    /**
     * A Jalali (`Y/m/d`) date window over `created_at`, via the shared
     * `JalaliDateRange` parser (#1054) that both ticket pages already used on
     * beta (#1080). An unparseable value yields `null` and the bound is
     * ignored rather than fatal — the contract the inline code had.
     *
     * @param  Builder<Ticket>  $query
     */
    protected function applyTicketDateRange(Builder $query, ?string $dateFrom, ?string $dateTo): void
    {
        if ($from = JalaliDateRange::parse($dateFrom)) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = JalaliDateRange::parse($dateTo, endOfDay: true)) {
            $query->where('created_at', '<=', $to);
        }
    }

    /**
     * Free-text `LIKE` search over the given columns, grouped so it ANDs with
     * the rest of the query.
     *
     * The column list is a parameter because the inbox searches `content` too
     * while monitoring does not — a deliberate difference this extraction
     * must not erase.
     *
     * @param  Builder<Ticket>  $query
     * @param  array<int, string>  $columns
     */
    protected function applyTicketSearch(Builder $query, ?string $term, array $columns): void
    {
        if (empty($term)) {
            return;
        }

        $query->where(function (Builder $group) use ($term, $columns): void {
            foreach ($columns as $column) {
                $group->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }
}
