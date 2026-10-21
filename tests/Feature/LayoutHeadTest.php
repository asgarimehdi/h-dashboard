<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1184 — فونت Vazirmatn یک خانواده و یک منبع دارد: `@font-face`ها در
 * app.css، و shell اپ فقط preload می‌کند. هیچ پاسخی نباید `@font-face`
 * اینلاین یا مسیر شکستهٔ `/public/fonts/` را برگرداند.
 */
uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->withoutVite();

    $unit = Unit::factory()->create();
    $person = Person::factory()->create(['u_id' => $unit->id]);
    $this->user = User::factory()->create(['n_code' => $person->n_code]);
});

test('app shell preloads Vazirmatn and does not inline a font-face', function () {
    $response = $this->actingAs($this->user)->followingRedirects()->get('/');

    $response->assertOk()
        ->assertSee('fonts/Vazirmatn-Regular.woff2', false)
        ->assertSee('rel="preload"', false)
        ->assertSee('as="font"', false)
        ->assertDontSee('@font-face', false)
        ->assertDontSee('/public/fonts/', false);
});

test('login page does not inline a font-face or use the public fonts path', function () {
    $response = $this->get('/login');

    $response->assertOk()
        ->assertSee('fonts/Vazirmatn-Regular.woff2', false)
        ->assertSee('rel="preload"', false)
        ->assertSee('as="font"', false)
        ->assertDontSee('@font-face', false)
        ->assertDontSee('/public/fonts/', false);
});
