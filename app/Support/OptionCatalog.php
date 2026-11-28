<?php

namespace App\Support;

use App\Models\Disease;
use App\Models\Unit;
use App\Services\CacheInvalidationServiceInterface;

/**
 * Cached select options for Livewire filter/picker lists (#1761).
 *
 * These lists are view data: they change only when a Unit or Disease row is
 * written, so a search keystroke must not re-read them. Cached through the
 * versioned CacheInvalidationService — the #1715 pattern. A bare #[Computed]
 * memoizes inside one request only and buys nothing here.
 */
final class OptionCatalog
{
    /**
     * @param  array<int|string>  $accessibleUnitIds
     * @return list<array{value: int, label: string}>
     */
    public static function units(array $accessibleUnitIds, bool $activeOnly): array
    {
        /** @var list<array{value: int, label: string}> $options */
        $options = app(CacheInvalidationServiceInterface::class)->remember(
            'unit_hierarchy',
            ScopeCacheKey::hash($accessibleUnitIds),
            function () use ($accessibleUnitIds, $activeOnly): array {
                // Unconditional whereIn: [] must compile to 0 = 1 (fail-closed).
                // Never wrap this predicate in when()/! empty().
                $query = Unit::query()
                    ->whereIn('id', $accessibleUnitIds)
                    ->orderBy('name');

                if ($activeOnly) {
                    $query->where('is_active', true);
                }

                return $query->get(['id', 'name', 'is_active'])
                    ->map(static fn (Unit $unit): array => [
                        'value' => (int) $unit->id,
                        'label' => (string) $unit->name,
                    ])
                    ->values()
                    ->all();
            },
            60,
            ['view' => 'option-catalog.units', 'active_only' => $activeOnly],
        );

        return $options;
    }

    /**
     * Render-on-open unit-picker rows (#1955).
     *
     * Same scope/invalidation contract as units(), but the tree picker also
     * needs parent_id and the unit-type label.
     *
     * @param  array<int|string>  $accessibleUnitIds
     * @return list<array{id: int, name: string, parent_id: int|null, unit_type_name: string|null}>
     */
    public static function unitsForPicker(array $accessibleUnitIds): array
    {
        /** @var list<array{id: int, name: string, parent_id: int|null, unit_type_name: string|null}> $rows */
        $rows = app(CacheInvalidationServiceInterface::class)->remember(
            'unit_hierarchy',
            ScopeCacheKey::hash($accessibleUnitIds),
            static function () use ($accessibleUnitIds): array {
                // Unconditional whereIn: [] must compile to 0 = 1 (fail-closed).
                // Never wrap this predicate in when()/! empty().
                return Unit::query()
                    ->with('unitType:id,name')
                    ->whereIn('id', $accessibleUnitIds)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'parent_id', 'unit_type_id'])
                    ->map(static fn (Unit $unit): array => [
                        'id' => (int) $unit->id,
                        'name' => (string) $unit->name,
                        'parent_id' => $unit->parent_id !== null ? (int) $unit->parent_id : null,
                        'unit_type_name' => $unit->unitType?->name !== null ? (string) $unit->unitType->name : null,
                    ])
                    ->values()
                    ->all();
            },
            60,
            ['view' => 'option-catalog.unit-picker', 'active_only' => true],
        );

        return $rows;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function diseases(bool $activeOnly): array
    {
        /** @var list<array{value: int, label: string}> $options */
        $options = app(CacheInvalidationServiceInterface::class)->remember(
            'disease_catalog',
            'global',
            function () use ($activeOnly): array {
                $query = Disease::query()->orderBy('sort')->orderBy('name_fa');

                if ($activeOnly) {
                    $query->where('is_active', true);
                }

                return $query->get(['id', 'name_fa', 'is_active'])
                    ->map(static fn (Disease $disease): array => [
                        'value' => (int) $disease->id,
                        'label' => (string) $disease->name_fa,
                    ])
                    ->values()
                    ->all();
            },
            60,
            ['view' => 'option-catalog.diseases', 'active_only' => $activeOnly],
        );

        return $options;
    }
}
