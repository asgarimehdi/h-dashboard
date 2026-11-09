<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The registry of business tables the in-app backup may touch (issue #1375).
 *
 * Two facts live here:
 *
 * 1. WHICH tables are in scope — exactly the 32 business tables. System
 *    tables (`cache`, `sessions`, `jobs`, …), the Spatie permission tables
 *    and the PostGIS ledger are NOT in it. The issue body's list of 33 tables
 *    is wrong: `hardware_histories` is dropped by the migrate-to-audits
 *    migration and does not exist on a database migrated to the current tip.
 *
 * 2. HOW they depend on each other — the FK graph is read from
 *    `information_schema` at RUNTIME, never parsed out of the migrations:
 *    constraints also arrive through later alter-migrations, and a migration
 *    list would silently miss them. Every edge carries the referenced
 *    COLUMN, because the referenced column is not always `id`
 *    (`users.n_code → persons.n_code`).
 */
class TableRegistry
{
    /**
     * The 32 business tables, in a stable declaration order.
     *
     * @var list<string>
     */
    public const TABLES = [
        'activity_log_archives',
        'activity_logs',
        'attachments',
        'boundaries',
        'daily_reports',
        'disease_cases',
        'disease_field_defs',
        'diseases',
        'estekhdams',
        'hardware_audits',
        'hardwares',
        'maintenance_schedules',
        'notifications',
        'patients',
        'persons',
        'radifs',
        'regions',
        'semats',
        'tahsils',
        'task_activities',
        'ticket_comment_reactions',
        'ticket_comments',
        'tickets',
        'todos',
        'unit_populations',
        'unit_type_relationships',
        'unit_types',
        'units',
        'user_units',
        'users',
        'zabbix_devices',
        'zabbix_sync_logs',
    ];

    /**
     * Every FK edge of the current schema, cached per instance.
     *
     * @var list<ForeignKey>|null
     */
    private ?array $allForeignKeys = null;

    /**
     * The registry's own edges (both ends registered), cached per instance.
     *
     * @var list<ForeignKey>|null
     */
    private ?array $registryForeignKeys = null;

    /**
     * The topological order (parents first), cached per instance.
     *
     * @var list<string>|null
     */
    private ?array $topologicalOrder = null;

    /**
     * @var array<string, list<string>>
     */
    private array $columns = [];

    /**
     * The tables a backup may contain.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return self::TABLES;
    }

    /**
     * Is this table part of the backup registry?
     */
    public function isRegistered(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }

    /**
     * The FK edges between registered tables — the graph the restore order
     * and the validation both work from.
     *
     * @return list<ForeignKey>
     */
    public function foreignKeys(): array
    {
        if ($this->registryForeignKeys !== null) {
            return $this->registryForeignKeys;
        }

        $this->registryForeignKeys = array_values(array_filter(
            $this->allForeignKeys(),
            fn (ForeignKey $edge): bool => $this->isRegistered($edge->childTable)
                && $this->isRegistered($edge->parentTable)
        ));

        return $this->registryForeignKeys;
    }

    /**
     * The edges a table's rows hang on — the parents a row must resolve to.
     *
     * @return list<ForeignKey>
     */
    public function foreignKeysOf(string $childTable): array
    {
        return array_values(array_filter(
            $this->allForeignKeys(),
            fn (ForeignKey $edge): bool => $edge->childTable === $childTable
        ));
    }

    /**
     * The edges that point AT a table — the children that must stay valid
     * when the table's rows are replaced (owner decision Q1-(b)).
     *
     * @return list<ForeignKey>
     */
    public function childForeignKeysOf(string $parentTable): array
    {
        return array_values(array_filter(
            $this->allForeignKeys(),
            fn (ForeignKey $edge): bool => $edge->parentTable === $parentTable
        ));
    }

    /**
     * The tables in dependency order: a parent always comes before its
     * children, so a full restore can insert parents first and delete
     * children first without violating a single constraint.
     *
     * Self-referencing edges (`units.parent_id → units.id`) constrain rows
     * inside one table and are skipped here — they say nothing about the
     * order BETWEEN tables.
     *
     * @return list<string>
     */
    public function topologicalOrder(): array
    {
        if ($this->topologicalOrder !== null) {
            return $this->topologicalOrder;
        }

        $tables = self::TABLES;
        $position = array_flip($tables);

        /** @var array<string, list<string>> $children parent => children */
        $children = [];
        /** @var array<string, int> $inDegree */
        $inDegree = array_fill_keys($tables, 0);

        foreach ($this->foreignKeys() as $edge) {
            if ($edge->childTable === $edge->parentTable) {
                continue;
            }

            $children[$edge->parentTable][] = $edge->childTable;
            $inDegree[$edge->childTable]++;
        }

        // Deterministic: among the tables that are ready, the one declared
        // first in the registry goes first.
        $ready = [];
        foreach ($tables as $table) {
            if ($inDegree[$table] === 0) {
                $ready[] = $table;
            }
        }

        $order = [];
        while ($ready !== []) {
            $table = array_shift($ready);
            $order[] = $table;

            foreach ($children[$table] ?? [] as $child) {
                $inDegree[$child]--;

                if ($inDegree[$child] !== 0) {
                    continue;
                }

                $insertAt = count($ready);
                foreach ($ready as $index => $candidate) {
                    if ($position[$candidate] > $position[$child]) {
                        $insertAt = $index;
                        break;
                    }
                }

                array_splice($ready, $insertAt, 0, [$child]);
            }
        }

        if (count($order) !== count($tables)) {
            $missing = array_values(array_diff($tables, $order));

            throw new RuntimeException(
                'The foreign-key graph of the backup registry has a cycle; the restore order cannot be computed: '
                .implode(', ', $missing)
            );
        }

        $this->topologicalOrder = $order;

        return $order;
    }

    /**
     * The column names of a table, in declaration order.
     *
     * @return list<string>
     */
    public function columns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }

        $rows = DB::select(
            'SELECT column_name FROM information_schema.columns'
            .' WHERE table_schema = current_schema() AND table_name = ?'
            .' ORDER BY ordinal_position',
            [$table]
        );

        return $this->columns[$table] = array_map(
            fn ($row): string => (string) $row->column_name,
            $rows
        );
    }

    /**
     * Every FK edge of the current schema, including the ones whose child or
     * parent is outside the registry (the Spatie permission tables).
     *
     * @return list<ForeignKey>
     */
    private function allForeignKeys(): array
    {
        if ($this->allForeignKeys !== null) {
            return $this->allForeignKeys;
        }

        $rows = DB::select(
            'SELECT tc.table_name AS child_table, kcu.column_name AS child_column,'
            .' ccu.table_name AS parent_table, ccu.column_name AS parent_column,'
            .' rc.delete_rule AS on_delete'
            .' FROM information_schema.table_constraints tc'
            .' JOIN information_schema.key_column_usage kcu'
            .' ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema'
            .' JOIN information_schema.constraint_column_usage ccu'
            .' ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema'
            .' JOIN information_schema.referential_constraints rc'
            .' ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema'
            .' WHERE tc.constraint_type = \'FOREIGN KEY\' AND tc.table_schema = current_schema()'
            .' ORDER BY tc.table_name, kcu.column_name'
        );

        return $this->allForeignKeys = array_map(
            fn ($row): ForeignKey => new ForeignKey(
                childTable: (string) $row->child_table,
                childColumn: (string) $row->child_column,
                parentTable: (string) $row->parent_table,
                parentColumn: (string) $row->parent_column,
                onDelete: (string) $row->on_delete,
            ),
            $rows
        );
    }
}
