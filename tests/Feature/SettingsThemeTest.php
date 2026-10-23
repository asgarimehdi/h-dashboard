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

    public function test_the_settings_page_offers_all_seven_themes(): void
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
        $this->assertSame([
            'light', 'cupcake', 'bumblebee', 'emerald', 'corporate', 'retro',
            'cyberpunk', 'valentine', 'garden', 'lofi', 'pastel', 'fantasy',
            'wireframe', 'cmyk', 'autumn', 'acid', 'lemonade', 'winter', 'nord',
            'caramellatte', 'silk',
        ], ThemeFamilies::LIGHT);
        $this->assertSame([
            'dark', 'synthwave', 'halloween', 'forest', 'aqua', 'black', 'luxury',
            'dracula', 'business', 'night', 'coffee', 'dim', 'sunset', 'abyss',
        ], ThemeFamilies::DARK);
        $this->assertSame([], array_intersect(ThemeFamilies::LIGHT, ThemeFamilies::DARK));

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

        $this->assertNull(ThemeFamilies::familyOf('not-a-theme'));
        $this->assertFalse(ThemeFamilies::isValid(null, 'light'));
    }

    public function test_enabled_themes_in_app_css_match_theme_families(): void
    {
        $css = file_get_contents(base_path('resources/css/app.css'));
        $this->assertIsString($css);

        preg_match('/themes:\\s*([^;]+);/', $css, $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'themes: line not found in app.css');

        $raw = $matches[1];
        // Strip flags like --default / --prefersdark.
        $names = [];
        foreach (preg_split('/,/', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $tokens = preg_split('/\\s+/', $part);
            $names[] = $tokens[0];
        }

        $expected = array_merge(ThemeFamilies::LIGHT, ThemeFamilies::DARK);
        sort($names);
        sort($expected);
        $this->assertSame($expected, $names, 'app.css themes must equal LIGHT+DARK');

        // Flags preserved.
        $this->assertStringContainsString('fantasy --default', $raw);
        $this->assertStringContainsString('luxury --prefersdark', $raw);
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
