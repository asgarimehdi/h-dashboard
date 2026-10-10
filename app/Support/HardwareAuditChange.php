<?php

namespace App\Support;

use App\Models\Hardware;

/**
 * The `hardware_audits.changes` contract (#927).
 *
 * The column is documented as a **field-level diff list**, `[{field, old, new}]`
 * (see the `create_hardware_audits_table` migration), and that is the shape the
 * observer's `created` / `updated` writers and the API's `bulk_mark` all emit.
 * Four independent readers assume it: the audit export, the Livewire audit
 * modal, the rollback/restore loops, and the `?field=` filter.
 *
 * `HardwareController::bulkDelete()` was the single writer that did not — it
 * handed `$hw->getAttributes()` (a column => value **map**) to
 * `batchInsertAudits()`, so those rows decoded to an assoc array. The export
 * then interpolated `$c['field']` on a string and threw a `TypeError` out of the
 * row mapper, taking the whole export stream with it.
 *
 * This class owns both halves of the fix:
 *
 *  - **Write** — {@see self::deletionSnapshot()} builds the documented shape for
 *   `bulk_delete`, reusing {@see self::AUDITED_FIELDS} so `created` and
 *   `bulk_delete` agree on which fields are audit-worthy, and
 *   {@see self::toDiffList()} coerces anything else a future bulk writer might
 *   hand over instead of letting it reach the column as an object.
 *  - **Read** — {@see self::lines()} renders *any* stored payload, so a row that
 *   predates the fix (or was written by raw SQL) degrades to a readable
 *   one-liner instead of a 500 or a blank panel.
 */
final class HardwareAuditChange
{
    /**
     * The attributes worth auditing on a device.
     *
     * Shared by the observer's `created` writer and the `bulk_delete` snapshot;
     * `id` / timestamps are deliberately absent.
     *
     * @var list<string>
     */
    public const AUDITED_FIELDS = [
        'n_code', 'pc_name', 'type', 'os', 'cpu', 'ram', 'hdd', 'net_type',
        'switch', 'port', 'vlan', 'motherboard', 'comments',
        'ip_valid', 'ip_local', 'mac', 'shutdown', 'mark', 'clean_at',
    ];

    /**
     * The value written into `new` for a device that is about to disappear.
     */
    public const DELETED_VALUE = 'deleted';

    /**
     * Placeholder for "there is nothing on the other side", matching the
     * `created` writer's use of '—' for `old`.
     */
    public const NO_VALUE = '—';

    /**
     * Build the diff list for a device that is about to be deleted: every
     * audited field that actually holds a value, as `{field, old, new}`.
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function deletionSnapshot(Hardware $hardware): array
    {
        $changes = [];

        foreach (self::AUDITED_FIELDS as $field) {
            $value = $hardware->getAttribute($field);

            if ($value !== null && $value !== '') {
                $changes[] = [
                    'field' => $field,
                    'old' => self::formatValueForDisplay($value),
                    'new' => self::DELETED_VALUE,
                ];
            }
        }

        return $changes;
    }

    /**
     * Coerce a payload on its way into the column into the documented diff list.
     *
     * `HardwareAudit::insert()` bypasses casts, so this is the only place a
     * guard can actually bite — a cast would never see a raw bulk insert. The
     * conversion is deliberately lossless rather than a rejection: a malformed
     * audit payload must never be the reason a bulk delete fails.
     *
     * @return list<array{field: string, old: mixed, new: mixed}>|null
     */
    public static function toDiffList(mixed $changes): ?array
    {
        if ($changes === null) {
            return null;
        }

        if (! is_array($changes)) {
            $changes = [$changes];
        }

        if (self::isDiffList($changes)) {
            return array_values($changes);
        }

        // Not a diff list: treat every key as a field whose value is present.
        // Array keys are `string|int` because PHP folds numeric strings to ints.
        return array_map(
            fn (string|int $field, mixed $value): array => [
                'field' => (string) $field,
                'old' => $value,
                'new' => self::NO_VALUE,
            ],
            array_keys($changes),
            $changes
        );
    }

    /**
     * Does this payload carry renderable field-level diffs?
     */
    public static function isDiffList(mixed $changes): bool
    {
        if (! is_array($changes) || $changes === [] || ! array_is_list($changes)) {
            return false;
        }

        foreach ($changes as $entry) {
            if (! is_array($entry) || ! array_key_exists('field', $entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render any stored payload as one readable string per field.
     *
     * Diff entries become `field: old → new`; a legacy attribute map becomes
     * `field: value`; a list of scalars is rendered as-is. Returns `[]` when
     * there is nothing to show, so both readers collapse "empty" to the same
     * empty cell.
     *
     * @return list<string>
     */
    public static function lines(mixed $changes): array
    {
        if (! is_array($changes) || $changes === []) {
            return [];
        }

        if (self::isDiffList($changes)) {
            return array_map(
                fn (array $entry): string => sprintf(
                    '%s: %s → %s',
                    $entry['field'],
                    self::renderValue($entry['old'] ?? self::NO_VALUE),
                    self::renderValue($entry['new'] ?? self::NO_VALUE)
                ),
                $changes
            );
        }

        // A bare list has no meaningful keys, so only the values are worth showing.
        if (array_is_list($changes)) {
            return array_map(
                fn (mixed $value): string => is_scalar($value) ? (string) $value : self::formatValueForDisplay($value),
                $changes
            );
        }

        return array_map(
            fn (string|int $field, mixed $value): string => "{$field}: ".self::formatValueForDisplay($value),
            array_keys($changes),
            $changes
        );
    }

    /**
     * Format a value for display in the changes log.
     */
    public static function formatValueForDisplay(mixed $value): string
    {
        if ($value === null) {
            return self::NO_VALUE;
        }
        if (is_bool($value)) {
            return $value ? 'بله' : 'خیر';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * Interpolate an already-stored diff value without changing how it reads.
     *
     * Scalars render exactly as string interpolation has always rendered them
     * (`false` → '', `true` → '1'), which is what the audit rows in production
     * already contain. Anything else goes through the display formatter so a
     * nested array cannot throw out of the reader.
     */
    private static function renderValue(mixed $value): string
    {
        return $value === null || is_scalar($value)
            ? (string) $value
            : self::formatValueForDisplay($value);
    }
}
