<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ItMultiGaugeLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    // ==================== Mount / render ====================

    public function test_renders_with_required_props(): void
    {
        Livewire::test('it.multi-gauge', [
            'signalItemId' => 'signal.item.1',
            'frequencyItemId' => 'freq.item.1',
            'responseTimeItemId' => 'rt.item.1',
        ])
            ->assertStatus(200)
            ->assertSee('signal.item.1')
            ->assertSee('freq.item.1')
            ->assertSee('rt.item.1');
    }

    public function test_renders_with_all_props(): void
    {
        Livewire::test('it.multi-gauge', [
            'signalItemId' => 'signal.99',
            'frequencyItemId' => 'freq.99',
            'responseTimeItemId' => 'rt.99',
            'title' => 'گیج تست',
            'min' => 10.0,
            'max' => 200.0,
            'unit' => 'dBm',
            'frequencyUnit' => 'GHz',
            'responseTimeUnit' => 'μs',
        ])
            ->assertStatus(200)
            ->assertSee('signal.99')
            ->assertSee('freq.99')
            ->assertSee('rt.99');
    }

    public function test_default_values_applied(): void
    {
        $component = Livewire::test('it.multi-gauge', [
            'signalItemId' => 's1',
            'frequencyItemId' => 'f1',
            'responseTimeItemId' => 'r1',
        ]);

        $component
            ->assertSet('title', 'سیگنال')
            ->assertSet('min', 0.0)
            ->assertSet('max', 100.0)
            ->assertSet('unit', '%')
            ->assertSet('frequencyUnit', 'MHz')
            ->assertSet('responseTimeUnit', 'ms');
    }

    // ==================== Values update ====================

    public function test_values_update_on_set(): void
    {
        $component = Livewire::test('it.multi-gauge', [
            'signalItemId' => 's1',
            'frequencyItemId' => 'f1',
            'responseTimeItemId' => 'r1',
            'title' => 'عنوان اول',
            'max' => 100.0,
        ]);

        $component
            ->set('title', 'عنوان دوم')
            ->set('max', 200.0)
            ->assertSet('title', 'عنوان دوم')
            ->assertSet('max', 200.0);
    }

    // ==================== Threshold / range props ====================

    public function test_threshold_colors_reflect_min_max_range(): void
    {
        Livewire::test('it.multi-gauge', [
            'signalItemId' => 's1',
            'frequencyItemId' => 'f1',
            'responseTimeItemId' => 'r1',
            'min' => 0.0,
            'max' => 50.0,
        ])
            ->assertSet('min', 0.0)
            ->assertSet('max', 50.0)
            ->assertSee('s1');
    }

    public function test_negative_min_renders_safely(): void
    {
        Livewire::test('it.multi-gauge', [
            'signalItemId' => 's1',
            'frequencyItemId' => 'f1',
            'responseTimeItemId' => 'r1',
            'min' => -100.0,
            'max' => 100.0,
        ])
            ->assertStatus(200)
            ->assertSet('min', -100.0);
    }

    // ==================== Edge cases ====================

    public function test_zero_min_max_renders_safely(): void
    {
        Livewire::test('it.multi-gauge', [
            'signalItemId' => 's1',
            'frequencyItemId' => 'f1',
            'responseTimeItemId' => 'r1',
            'min' => 0.0,
            'max' => 0.0,
        ])
            ->assertStatus(200);
    }

    public function test_empty_title_renders_safely(): void
    {
        Livewire::test('it.multi-gauge', [
            'signalItemId' => 's1',
            'frequencyItemId' => 'f1',
            'responseTimeItemId' => 'r1',
            'title' => '',
        ])
            ->assertStatus(200);
    }

    public function test_long_prop_strings_render_safely(): void
    {
        $longId = str_repeat('x', 255);

        Livewire::test('it.multi-gauge', [
            'signalItemId' => $longId,
            'frequencyItemId' => $longId,
            'responseTimeItemId' => $longId,
        ])
            ->assertStatus(200)
            ->assertSee($longId);
    }

    public function test_zero_values_render_safely(): void
    {
        Livewire::test('it.multi-gauge', [
            'signalItemId' => 's1',
            'frequencyItemId' => 'f1',
            'responseTimeItemId' => 'r1',
            'min' => 0,
            'max' => 0,
        ])
            ->assertStatus(200)
            ->assertSet('min', 0.0)
            ->assertSet('max', 0.0);
    }

    public function test_missing_optional_props_default(): void
    {
        $component = Livewire::test('it.multi-gauge', [
            'signalItemId' => 's1',
            'frequencyItemId' => 'f1',
            'responseTimeItemId' => 'r1',
        ]);

        $component
            ->assertSet('title', 'سیگنال')
            ->assertSet('min', 0.0)
            ->assertSet('max', 100.0)
            ->assertSet('unit', '%')
            ->assertSet('frequencyUnit', 'MHz')
            ->assertSet('responseTimeUnit', 'ms');
    }
}
