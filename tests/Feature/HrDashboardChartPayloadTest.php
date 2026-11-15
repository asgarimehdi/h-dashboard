<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1499 — /hr-dashboard polled the server about once a second: the
 * `morphed` hook called `component.call('chartPayload')`, and a Livewire method
 * call always renders → always morphs → fires `morphed` again, with no
 * equality short-circuit. The HR charts now read a server-rendered inert
 * `data-chart` block (`#hr-chart-data`), the same shape as
 * `#dashboard-chart-data` (#1352) and the #1042 reports.
 *
 * Two guards: the rendered DOM really carries the payload (nothing proves the
 * data reaches JS otherwise), and the JS source never grows the server call
 * back — a request inside a `morphed` hook re-opens the loop.
 */
class HrDashboardChartPayloadTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_the_hr_dashboard_renders_its_chart_payload_into_the_dom(): void
    {
        $result = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($result['user']);

        $rendered = Livewire::test('hr.dashboard')
            ->assertOk()
            ->assertSeeHtml('id="hr-chart-data"');

        $this->assertSame(1, preg_match(
            '/id="hr-chart-data"[^>]*data-chart=\'([^\']*)\'/',
            $rendered->html(),
            $matches,
        ), 'the inert chart block must carry a data-chart payload');

        $payload = json_decode($matches[1], true);

        $this->assertSame(
            ['byUnit', 'bySemat', 'byTahsil', 'byEstekhdam'],
            array_keys(is_array($payload) ? $payload : []),
        );
    }

    public function test_the_hr_chart_module_never_calls_a_component_method(): void
    {
        $source = file_get_contents(resource_path('js/dashboard-charts.js'));

        $this->assertStringNotContainsString("component.call('chartPayload')", $source);
        $this->assertStringNotContainsString("'chartPayload'", $source);
    }

    /**
     * Issue #1536 — a data-driven <style> block inside a single-file component
     * is dead weight: Livewire's SingleFileParser::extractStylePortion() lifts
     * the tag out verbatim and Blade never translates it, so the `{{ … }}` in
     * it never becomes CSS. The third recurrence of the same class (#1352 →
     * #1496 → #1536), and no render assertion can catch it because the block
     * is gone from the DOM.
     *
     * Tests the INNER content only (SingleFileParser::generateStyleContents()):
     * every nonce'd tag already carries `{{` in its nonce attribute, so matching
     * the whole tag would flag seven static CSS blocks that are perfectly fine.
     */
    public function test_no_single_file_component_puts_blade_syntax_inside_a_column_zero_style_tag(): void
    {
        $offenders = collect(File::allFiles(resource_path('views/livewire')))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php')
                && str_contains(file_get_contents($f->getPathname()), 'return new class'))
            ->filter(function ($f) {
                $contents = preg_replace('/@verbatim\s*.*?@endverbatim/s', '', file_get_contents($f->getPathname()));
                // mirror SingleFileParser::extractStylePortion() (locked v4.4.8),
                // but test the INNER content only (generateStyleContents):
                // every nonce'd tag carries {{ in its nonce attribute.
                preg_match_all('/<style\b[^>]*>(.*?)<\/style>/s', $contents, $m);

                return (bool) preg_grep('/\{\{|\{!!|@php|@if|@foreach|@json/', $m[1] ?? []);
            })
            ->map(fn ($f) => $f->getRelativePathname())->values()->all();

        expect($offenders)->toBe([]);
    }
}
