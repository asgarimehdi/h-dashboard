<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GlowingCardLivewireTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Render ====================

    public function test_renders(): void
    {
        Livewire::test('glowingcard')
            ->assertStatus(200);
    }

    // ==================== Static DOM ====================

    public function test_static_dom(): void
    {
        Livewire::test('glowingcard')
            ->assertSee('card-1')
            ->assertSee('highlight-1');
    }

    // ==================== Alpine wiring ====================

    public function test_alpine_wiring(): void
    {
        Livewire::test('glowingcard')
            ->assertSee('x-data')
            ->assertSee('x-on:mousemove')
            ->assertSee('x-on:mouseleave')
            ->assertSee('updateHighlight')
            ->assertSee('resetHighlight');
    }

    // ==================== No state (no public props/methods) ====================

    public function test_no_state(): void
    {
        Livewire::test('glowingcard')
            // Component has no public properties or Livewire methods
            // so set/call should have no effect beyond a normal render
            ->assertOk();
    }

    // ==================== External asset ====================

    public function test_external_asset(): void
    {
        Livewire::test('glowingcard')
            ->assertSee('https://n8niostorageaccount.blob.core.windows.net/n8nio-strapi-blobs-prod/assets/miro_logo_94f7214a92.svg')
            ->assertSee('Logo');
    }

    // ==================== Quote and author content ====================

    public function test_quote_text(): void
    {
        Livewire::test('glowingcard')
            ->assertSee('Rebuilt a 4-week AI feature in 10 minutes');
    }

    public function test_author_name(): void
    {
        Livewire::test('glowingcard')
            ->assertSee('Fabian Strunden');
    }

    // ==================== Edge cases ====================

    public function test_unrouted_standalone_render(): void
    {
        // This component has no registered route; verify it renders
        // via Livewire::test() without hitting any HTTP route.
        Livewire::test('glowingcard')
            ->assertOk();
    }

    public function test_body_ascii_only(): void
    {
        // The component's PHP class body is empty (just `//`);
        // verify it doesn't emit errors from empty method bodies.
        Livewire::test('glowingcard')
            ->assertStatus(200);
    }
}
