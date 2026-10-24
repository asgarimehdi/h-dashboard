<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1042 — every report filter change used to fire a second
 * `$wire.chartPayload()` Livewire method request on top of the render that had
 * already computed the same aggregates. The three charts now read a
 * server-rendered `data-chart` payload that morphs with the component, exactly
 * like `reports/persons` (#858).
 *
 * Two guards: the rendered DOM really carries the payload (the old gap — no
 * test proved the data ever reached JS), and the source never grows a
 * `$wire.chartPayload()` call back.
 */
class ReportsChartPayloadTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function reportPayloadProvider(): array
    {
        return [
            'units' => ['reports.units', 'unitsChartData', ['byType']],
            'todos' => ['reports.todos', 'todosChartData', ['byStatus', 'byUnit']],
            'advanced' => ['reports.advanced', 'advancedChartData', ['byDay', 'byUnit', 'details']],
        ];
    }

    /**
     * @param  array<int, string>  $keys
     */
    #[DataProvider('reportPayloadProvider')]
    public function test_a_report_renders_its_chart_payload_into_the_dom(
        string $component,
        string $containerId,
        array $keys,
    ): void {
        $result = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($result['user']);

        $rendered = Livewire::test($component)->assertOk();

        $rendered->assertSeeHtml('id="'.$containerId.'"');
        $rendered->assertSeeHtml('data-chart=');

        foreach ($keys as $key) {
            $rendered->assertSee($key, false);
        }
    }

    public function test_the_report_scripts_no_longer_call_the_chart_payload_method(): void
    {
        foreach (['units', 'todos', 'advanced'] as $report) {
            $source = file_get_contents(resource_path("views/livewire/reports/{$report}.blade.php"));

            $this->assertStringNotContainsString(
                '$wire.chartPayload(',
                $source,
                "reports/{$report}.blade.php still fires a second chartPayload() request.",
            );
        }
    }
}
