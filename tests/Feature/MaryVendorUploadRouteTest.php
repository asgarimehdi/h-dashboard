<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Issue #951 — the vendored maryUI upload route is not mounted
|--------------------------------------------------------------------------
|
| `vendor/robsontenorio/mary/routes/web.php` registers `POST /mary/upload`
| from `MaryServiceProvider::boot()` via `loadRoutesFrom()`. The closure has
| no `$request->validate()` and reads BOTH the storage disk and the
| destination folder straight off the request body:
|
|     $disk   = $request->disk   ?? 'public';
|     $folder = $request->folder ?? 'editor';
|     $file = Storage::disk($disk)->put($folder, $request->file('file'), 'public');
|
| `config/mary.php` sets `route_prefix` to `''`, so it is mounted at the app
| root, behind nothing but `auth` (a login, not a permission). Every upload
| call site in this app enforces `mimes:` + `max:` by hand
| (livewire/tickets/⚡create.blade.php:163, livewire/tickets/⚡inbox.blade.php:691)
| — this route sat outside that policy because the policy lives in the
| callers, not at the boundary.
|
| The endpoint is unused (no `<x-editor>` / `<x-markdown>` in the app), so the
| fix is Option A: the application owns its route surface and does not mount
| the vendor write endpoint at all.
|
*/

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

test('the maryUI upload route is not registered', function () {
    expect(Route::has('mary.upload'))->toBeFalse();
});

test('the other maryUI routes stay mounted because the layout calls them', function () {
    // `<x-main>` (resources/views/components/layouts/app.blade.php:151) calls
    // `route('mary.toogle-sidebar')` while rendering, so removing the whole
    // vendor route file would 500 every page. Only the write endpoint goes.
    expect(Route::has('mary.toogle-sidebar'))->toBeTrue()
        ->and(Route::has('mary.spotlight'))->toBeTrue();
});

test('posting to the mary upload uri is a 404 for an authenticated user', function () {
    Storage::fake('public');
    Storage::fake('local');

    ['user' => $user] = $this->createUserWithUnit();
    $this->actingAs($user);

    $response = $this->post('/mary/upload', [
        'file' => UploadedFile::fake()->create('payload.php', 8),
        'disk' => 'local',
        'folder' => 'anything',
    ]);

    $response->assertNotFound();

    // No disk, no folder, no visibility argument — nothing was written.
    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
