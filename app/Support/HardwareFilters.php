<?php

namespace App\Support;

use App\Models\Hardware;
use App\Models\Person;
use App\Traits\PersianNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for the hardware list filters.
 *
 * Issue #1050: the twelve filters used to be implemented three times — the
 * `Hardware::scopeFilter*()` scopes, an inline block in
 * `Api\HardwareExportController`, and another inline block in the Livewire
 * `hardwares()` method. They had already drifted:
 *
 *   - `shutdown` / `mark` accepted `'true'` in the model scopes but only `'1'`
 *     in the export and Livewire copies, so a `shutdown=true` filter showed
 *     rows in the UI but exported everything;
 *   - the general `search` matched a full person name only in the export and
 *     Livewire copies (they build `CONCAT(f_name, ' ', l_name)`), not in the
 *     model scopes.
 *
 * Every consumer now routes through this class, so the three surfaces cannot
 * disagree. Each `apply*()` method takes the *raw* filter value exactly as it
 * arrives from a request or a Livewire property; normalization and LIKE
 * wildcard escaping happen here so the term side and the column side fold
 * identically.
 */
class HardwareFilters
{
    use PersianNormalizer;

    /**
     * User-facing type aliases mapped to the stored value.
     *
     * @var array<string, string>
     */
    public const TYPE_ALIASES = [
        'desktop' => 'pc',
        'پی‌سی' => 'pc',
    ];

    /**
     * `hardwares` text columns whose stored value is already normalized by the
     * model's `saving` hook, so only the term needs folding.
     *
     * @var list<string>
     */
    private const NORMALIZED_TEXT_FIELDS = ['os', 'cpu', 'ram', 'hdd', 'net_type'];

    /**
     * Largest `n_code` list the general search will pre-resolve into an `IN`
     * arm before falling back to the semi-join (#1482).
     *
     * Patch B: PostgreSQL builds a BitmapOr on the outer relation only when
     * every OR arm is indexable there; `whereHas('person')` compiles to an
     * EXISTS semi-join, so the hardware scan stays sequential even when its
     * subplan is fully indexed (126.7 ms vs 3.1 ms with 800 codes in the
     * spike). The cap keeps a one-character term from building a 50k-element
     * parameter list — past it the semi-join is the cheaper shape.
     */
    public const JOIN_ARM_CAP = 5000;

    /**
     * Apply every supplied filter. Keys match the request/Livewire parameter
     * names: search, type, os, cpu, ram, hdd, shutdown, net_type, mark, person,
     * unit, semat.
     *
     * @param  Builder<Hardware>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Hardware>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        self::applySearch($query, $filters['search'] ?? null);
        self::applyType($query, $filters['type'] ?? null);

        foreach (self::NORMALIZED_TEXT_FIELDS as $field) {
            self::applyText($query, $field, $filters[$field] ?? null);
        }

        self::applyBoolean($query, 'shutdown', $filters['shutdown'] ?? null);
        self::applyBoolean($query, 'mark', $filters['mark'] ?? null);
        self::applyPerson($query, $filters['person'] ?? null);
        self::applyUnit($query, $filters['unit'] ?? null);
        self::applySemat($query, $filters['semat'] ?? null);

        return $query;
    }

    /**
     * General search across hardware columns and the owner's full name.
     *
     * The person arm is pre-resolved into an `IN` list instead of a semi-join:
     * see {@see self::JOIN_ARM_CAP}. The hardware columns stay BARE — they are
     * normalized by the model's `saving` hook and already trigram-indexed.
     *
     * @param  Builder<Hardware>  $query
     */
    public static function applySearch(Builder $query, mixed $term): void
    {
        if (! self::filled($term)) {
            return;
        }

        $term = self::foldedTerm((string) $term);

        $codes = Person::query()
            ->where(fn (Builder $person) => $person
                ->where('f_name_folded', 'LIKE', "%{$term}%")
                ->orWhere('l_name_folded', 'LIKE', "%{$term}%")
                ->orWhere('full_name_folded', 'LIKE', "%{$term}%"))
            ->pluck('n_code')
            ->all();

        $query->where(function (Builder $q) use ($term, $codes) {
            $q->where('hardwares.pc_name', 'LIKE', "%{$term}%")
                ->orWhere('hardwares.n_code', 'LIKE', "%{$term}%")
                ->orWhere('hardwares.ip_valid', 'LIKE', "%{$term}%")
                ->orWhere('hardwares.ip_local', 'LIKE', "%{$term}%")
                ->orWhere('hardwares.mac', 'LIKE', "%{$term}%")
                ->orWhere('hardwares.comments', 'LIKE', "%{$term}%");

            // An empty list still goes through `orWhereIn()`: Laravel compiles
            // `whereIn([])` to `0 = 1`, the planner folds the constant away and
            // the hardware arms stay BitmapOr-able. Falling back to `whereHas`
            // on empty would re-introduce the outer sequential scan for the
            // common "no person matches this term" search.
            if (count($codes) <= self::JOIN_ARM_CAP) {
                $q->orWhereIn('hardwares.n_code', $codes);

                return;
            }

            $q->orWhereHas('person', function (Builder $person) use ($term) {
                $person->where('f_name_folded', 'LIKE', "%{$term}%")
                    ->orWhere('l_name_folded', 'LIKE', "%{$term}%")
                    ->orWhere('full_name_folded', 'LIKE', "%{$term}%");
            });
        });
    }

    /**
     * Hardware type, with the user-facing aliases mapped to the stored value.
     *
     * @param  Builder<Hardware>  $query
     */
    public static function applyType(Builder $query, mixed $type): void
    {
        if (! self::filled($type)) {
            return;
        }

        $type = (string) $type;
        $type = self::TYPE_ALIASES[$type] ?? $type;
        $type = self::normalizeForQuery($type);

        $query->where('hardwares.type', 'LIKE', "%{$type}%");
    }

    /**
     * LIKE match on one of the model-normalized `hardwares` text columns.
     *
     * @param  Builder<Hardware>  $query
     */
    public static function applyText(Builder $query, string $column, mixed $value): void
    {
        if (! self::filled($value)) {
            return;
        }

        $term = self::normalizeForQuery((string) $value);

        $query->where("hardwares.{$column}", 'LIKE', "%{$term}%");
    }

    /**
     * Boolean column filter. Accepts `'1'`, `'true'`, `1` or `true` as true so
     * the model, export and Livewire surfaces agree.
     *
     * @param  Builder<Hardware>  $query
     */
    public static function applyBoolean(Builder $query, string $column, mixed $value): void
    {
        if (! self::filled($value)) {
            return;
        }

        $query->where("hardwares.{$column}", self::isTruthy($value));
    }

    /**
     * Owner name / national code, matching the full name too.
     *
     * Compares the stored folded columns (#1482), which are generated from
     * `foldSeparatorsSql()` at write time, so the OR is indexable as a whole.
     *
     * @param  Builder<Hardware>  $query
     */
    public static function applyPerson(Builder $query, mixed $term): void
    {
        if (! self::filled($term)) {
            return;
        }

        $term = self::foldedTerm((string) $term);

        $query->whereHas('person', function (Builder $person) use ($term) {
            $person->where('f_name_folded', 'LIKE', "%{$term}%")
                ->orWhere('l_name_folded', 'LIKE', "%{$term}%")
                ->orWhere('n_code', 'LIKE', "%{$term}%")
                ->orWhere('full_name_folded', 'LIKE', "%{$term}%");
        });
    }

    /**
     * Unit name (via `persons.u_id` → `units`).
     *
     * @param  Builder<Hardware>  $query
     */
    public static function applyUnit(Builder $query, mixed $term): void
    {
        if (! self::filled($term)) {
            return;
        }

        $term = self::foldedTerm((string) $term);

        $query->whereHas('person.unit', function (Builder $unit) use ($term) {
            $unit->where('name_folded', 'LIKE', "%{$term}%");
        });
    }

    /**
     * Job-title name (via `persons.s_id` → `semats`).
     *
     * @param  Builder<Hardware>  $query
     */
    public static function applySemat(Builder $query, mixed $term): void
    {
        if (! self::filled($term)) {
            return;
        }

        $term = self::foldedTerm((string) $term);

        $query->whereHas('person.semat', function (Builder $semat) use ($term) {
            $semat->where('name_folded', 'LIKE', "%{$term}%");
        });
    }

    /**
     * A filter is present unless it is null or the empty string. `false` and
     * `0` count as present so a boolean filter can select "off".
     */
    private static function filled(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private static function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
