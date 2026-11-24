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
