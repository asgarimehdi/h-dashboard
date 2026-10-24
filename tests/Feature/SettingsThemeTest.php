<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ThemeFamilies;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(User::class);

/**
 * #1224 — the user picks one light and one dark theme; the header toggle
 * cycles between those two poles. Defaults must stay the pre-issue behaviour
 * (fantasy/dark) so a user without settings — and every guest — sees no change.
 */
class SettingsThemeTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_theme_defaults_are_fantasy_and_dark_without_settings(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertSet('lightTheme', 'fantasy')
            ->assertSet('darkTheme', 'dark');
    }

    public function test_theme_poles_persist(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->set('lightTheme', 'cupcake')
            ->set('darkTheme', 'coffee')
            ->call('save')
            ->assertDispatched('theme-poles-saved', lightTheme: 'cupcake', darkTheme: 'coffee');

        $settings = $user->refresh()->settings;
        $this->assertSame('cupcake', $settings['light_theme']);
        $this->assertSame('coffee', $settings['dark_theme']);
    }

    public function test_saving_theme_keeps_compact_mode_and_the_other_settings(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->set('compactMode', true)
            ->set('browserNotifications', true)
            ->set('dashboardRefresh', 30)
            ->call('save');

        Livewire::test('settings.index')
            ->set('lightTheme', 'cupcake')
            ->set('darkTheme', 'coffee')
            ->call('save');

        $settings = $user->refresh()->settings;
        $this->assertTrue($settings['compact_mode']);
        $this->assertTrue($settings['browser_notifications']);
        $this->assertSame(30, $settings['dashboard_refresh']);
        $this->assertSame('cupcake', $settings['light_theme']);
        $this->assertSame('coffee', $settings['dark_theme']);
    }

    public function test_a_theme_outside_its_family_is_rejected_without_writing(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->set('lightTheme', 'coffee')
            ->set('darkTheme', 'cupcake')
            ->call('save')
            ->assertNotDispatched('theme-poles-saved');

        $settings = $user->refresh()->settings ?? [];
        $this->assertArrayNotHasKey('light_theme', $settings);
        $this->assertArrayNotHasKey('dark_theme', $settings);
    }

    public function test_the_settings_page_offers_all_35_themes(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $component = Livewire::test('settings.index');
        foreach (ThemeFamilies::LIGHT as $theme) {
            $component->assertSeeHtml('option value="'.$theme.'"');
        }
        foreach (ThemeFamilies::DARK as $theme) {
            $component->assertSeeHtml('option value="'.$theme.'"');
        }
    }

    public function test_theme_families_are_disjoint_and_cover_every_enabled_theme(): void
    {
        // #1227: every daisyUI theme, in the plugin's own `themeOrder`.
        $this->assertSame([
            'light', 'cupcake', 'bumblebee', 'emerald', 'corporate', 'retro', 'cyberpunk',
            'valentine', 'garden', 'lofi', 'pastel', 'fantasy', 'wireframe', 'cmyk', 'autumn',
            'acid', 'lemonade', 'winter', 'nord', 'caramellatte', 'silk',
        ], ThemeFamilies::LIGHT);
        $this->assertSame([
            'dark', 'synthwave', 'halloween', 'forest', 'aqua', 'black', 'luxury', 'dracula',
            'business', 'night', 'coffee', 'dim', 'sunset', 'abyss',
        ], ThemeFamilies::DARK);
        $this->assertSame([], array_intersect(ThemeFamilies::LIGHT, ThemeFamilies::DARK));
        $this->assertCount(35, [...ThemeFamilies::LIGHT, ...ThemeFamilies::DARK]);

        foreach (ThemeFamilies::LIGHT as $theme) {
            $this->assertSame('light', ThemeFamilies::familyOf($theme));
            $this->assertTrue(ThemeFamilies::isValid($theme, 'light'));
            $this->assertFalse(ThemeFamilies::isValid($theme, 'dark'));
        }

        foreach (ThemeFamilies::DARK as $theme) {
            $this->assertSame('dark', ThemeFamilies::familyOf($theme));
            $this->assertTrue(ThemeFamilies::isValid($theme, 'dark'));
            $this->assertFalse(ThemeFamilies::isValid($theme, 'light'));
        }

        // Every enabled theme has a Persian label — a missing key would leak the
        // English theme name into the select.
        foreach ([...ThemeFamilies::LIGHT, ...ThemeFamilies::DARK] as $theme) {
            $this->assertArrayHasKey($theme, ThemeFamilies::LABELS);
            $this->assertNotSame('', ThemeFamilies::LABELS[$theme]);
        }

        $this->assertNull(ThemeFamilies::familyOf('not-a-theme'));
        $this->assertFalse(ThemeFamilies::isValid(null, 'light'));
    }

    /**
     * #1227 — `ThemeFamilies` and the daisyUI `themes:` line are two lists that
     * must be the same set. Until now nothing connected them: adding a theme to
     * one and not the other either shipped an unselectable theme (CSS only) or
     * a selectable theme with no token block (broken colours, caught only by the
     * browser e2e). This guard parses the line and locks the set + the two flags.
     */
    public function test_enabled_themes_in_app_css_match_theme_families(): void
    {
        $css = file_get_contents(base_path('resources/css/app.css'));
        $this->assertIsString($css);

        $this->assertSame(1, preg_match('/@plugin\s+"daisyui"\s*\{(.*?)\}/s', $css, $block), 'daisyUI plugin block not found');
        $this->assertSame(1, preg_match('/\bthemes:\s*(.*?);/s', $block[1], $match), 'no `themes:` line in the daisyUI block');

        $entries = array_values(array_filter(array_map('trim', explode(',', $match[1])), fn (string $entry): bool => $entry !== ''));

        $enabled = [];
        $flags = [];
        foreach ($entries as $entry) {
            $parts = preg_split('/\s+/', $entry);
            $theme = $parts[0];
            $enabled[] = $theme;
            if (isset($parts[1])) {
                $flags[$theme] = $parts[1];
            }
        }

        // A duplicated name is not an error for daisyUI, but it means the list
        // was edited by hand and a theme may have been lost in the process.
        $this->assertSame(array_values(array_unique($enabled)), $enabled, 'duplicate theme in the daisyUI `themes:` line');

        $expected = [...ThemeFamilies::LIGHT, ...ThemeFamilies::DARK];
        sort($expected);
        sort($enabled);
        $this->assertSame($expected, $enabled, 'app.css `themes:` and ThemeFamilies must be the same set');

        // Pre-issue behaviour: a guest (no settings) must keep fantasy on :root
        // and luxury for prefers-color-scheme: dark. `themes: all` drops both.
        $this->assertSame(['fantasy' => '--default', 'luxury' => '--prefersdark'], $flags);
        $this->assertNotContains('all', $enabled, '`themes: all` drops the fantasy/luxury flags');
    }

    public function test_the_header_toggle_renders_the_saved_poles(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->settings = ['light_theme' => 'cupcake', 'dark_theme' => 'luxury'];
        $user->save();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertSeeHtml("matches ? 'luxury' : 'cupcake'");
    }

    public function test_the_header_toggle_falls_back_to_the_default_poles(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertSeeHtml("matches ? 'dark' : 'fantasy'");
    }
}
