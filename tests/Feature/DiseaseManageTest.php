<?php

use App\Models\Disease;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
uses(InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();
});

test('permission seeder creates manage_diseases and grants it to admin', function () {
    // Second run in the same test: the seeder must stay idempotent.
    $this->seed(PermissionSeeder::class);

    expect(Permission::query()->where('name', 'manage_diseases')->count())->toBe(1);
    expect(Role::query()->where('name', 'admin')->firstOrFail()->hasPermissionTo('manage_diseases'))->toBeTrue();
});

test('page requires manage_diseases', function () {
    ['user' => $unauthorized] = $this->createUserWithUnit();
    $this->actingAs($unauthorized);

    Livewire::test('disease.manage')->assertStatus(403);

    ['user' => $authorized] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($authorized);

    Livewire::test('disease.manage')->assertStatus(200);
});

test('create disease from component', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    Livewire::test('disease.manage')
        ->call('startCreateDisease')
        ->assertSet('sort', 1)
        ->set('nameFa', 'بیماری آزمایشی')
        ->set('slug', 'test-disease')
        ->call('saveDisease')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('diseases', [
        'name_fa' => 'بیماری آزمایشی',
        'slug' => 'test-disease',
        'is_active' => true,
        'sort' => 1,
    ]);
});

test('slug must be unique', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    Disease::factory()->create(['name_fa' => 'بیماری موجود', 'slug' => 'duplicate-slug']);
    $countBefore = Disease::query()->count();

    Livewire::test('disease.manage')
        ->call('startCreateDisease')
        ->set('nameFa', 'بیماری دوم')
        ->set('slug', 'duplicate-slug')
        ->call('saveDisease')
        ->assertHasErrors(['slug' => 'unique']);

    expect(Disease::query()->count())->toBe($countBefore);
});

test('slug rejects persian letters and spaces', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    Livewire::test('disease.manage')
        ->call('startCreateDisease')
        ->set('nameFa', 'بیماری فارسی')
        ->set('slug', 'بیماری من')
        ->call('saveDisease')
        ->assertHasErrors(['slug' => 'regex']);

    $this->assertDatabaseCount('diseases', 0);
});

test('update disease', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    $disease = Disease::factory()->create(['name_fa' => 'نام قبلی', 'slug' => 'old-slug']);

    Livewire::test('disease.manage')
        ->call('editDisease', $disease->id)
        ->set('nameFa', 'نام تازه')
        ->call('saveDisease')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('diseases', [
        'id' => $disease->id,
        'name_fa' => 'نام تازه',
        'slug' => 'old-slug',
    ]);
    $this->assertDatabaseCount('diseases', 1);
});

test('delete disease without cases', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    $disease = Disease::factory()->create();

    Livewire::test('disease.manage')
        ->call('deleteDisease', $disease->id);

    $this->assertDatabaseMissing('diseases', ['id' => $disease->id]);
});
