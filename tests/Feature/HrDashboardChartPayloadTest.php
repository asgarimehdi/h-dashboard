<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
