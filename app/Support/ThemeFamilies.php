<?php

namespace App\Support;

/**
 * #1224 — the two theme poles a user may pick from the settings page.
 *
 * The split is measured, not aesthetic: daisyUI declares `color-scheme` per
 * theme and the OKLCH lightness of `--color-base-100` lands in two disjoint
 * bands (issue comment 6364662792):
 *
 *   light: light 1.000, fantasy 1.000, cupcake 0.978
 *   dark:  dark 0.253, coffee 0.240, synthwave 0.150, luxury 0.141
 *
 * `coffee` is dark on all three signals (declared `color-scheme: dark`, L inside
 * the dark band, and its own neutral-content/base-300 pair). Keep this class as
 * the single source of truth: `save()` whitelists against it, the settings page
 * builds its selects from it, and the header toggle reads its poles through it.
 *
 * Values are interpolated into Alpine strings on every page (MaryUI's
 * `<x-theme-toggle>`), so anything outside these lists must never be persisted.
 */
final class ThemeFamilies
{
    /** @var list<string> */
    public const LIGHT = ['light', 'cupcake', 'fantasy'];

    /** @var list<string> */
    public const DARK = ['dark', 'synthwave', 'coffee', 'luxury'];

    /** The pre-#1224 hard-coded poles: a user without settings sees no change. */
    public const DEFAULT_LIGHT = 'fantasy';

    public const DEFAULT_DARK = 'dark';

    /** @var array<string, string> */
    public const LABELS = [
        'light' => 'روشن',
        'cupcake' => 'کاپ‌کیک',
        'fantasy' => 'فانتزی',
        'dark' => 'تیره',
        'synthwave' => 'سینت‌ویو',
        'coffee' => 'قهوه‌ای',
        'luxury' => 'لوکس',
    ];

    /**
     * The family a theme belongs to, or null for an unknown/absent value.
     */
    public static function familyOf(?string $theme): ?string
    {
        if ($theme === null) {
            return null;
        }

        if (in_array($theme, self::LIGHT, true)) {
            return 'light';
        }

        if (in_array($theme, self::DARK, true)) {
            return 'dark';
        }

        return null;
    }

    /**
     * Whether `$theme` may be stored as the pole for `$family` ('light'|'dark').
     */
    public static function isValid(?string $theme, string $family): bool
    {
        $themes = match ($family) {
            'light' => self::LIGHT,
            'dark' => self::DARK,
            default => [],
        };

        return $theme !== null && in_array($theme, $themes, true);
    }

    /**
     * The saved pole for `$family`, or the default when it is missing/invalid.
     */
    public static function poleOrDefault(?string $theme, string $family): string
    {
        return self::isValid($theme, $family)
            ? $theme
            : ($family === 'light' ? self::DEFAULT_LIGHT : self::DEFAULT_DARK);
    }

    /**
     * Options for MaryUI's `<x-select>` (paired with option-value/option-label).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function lightOptions(): array
    {
        return self::options(self::LIGHT);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function darkOptions(): array
    {
        return self::options(self::DARK);
    }

    /**
     * @param  list<string>  $themes
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $themes): array
    {
        return array_map(
            static fn (string $theme): array => [
                'value' => $theme,
                'label' => self::LABELS[$theme] ?? $theme,
            ],
            $themes
        );
    }
}
