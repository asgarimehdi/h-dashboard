<?php

use App\Models\Disease;
use App\Models\DiseaseFieldDef;
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

test('create field def with select options', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    $disease = Disease::factory()->create();

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('startCreateFieldDef')
        ->set('fieldKey', 'stage')
        ->set('labelFa', 'مرحله')
        ->set('type', 'select')
        ->set('optionsText', "I\nII\nIII")
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    $def = DiseaseFieldDef::query()
        ->where('disease_id', $disease->id)
        ->where('field_key', 'stage')
        ->firstOrFail();

    expect($def->options)->toBe(['I', 'II', 'III'])
        ->and($def->is_active)->toBeTrue()
        ->and($def->required)->toBeFalse();
});

test('options required for select type', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    $disease = Disease::factory()->create();

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('startCreateFieldDef')
        ->set('fieldKey', 'stage')
        ->set('labelFa', 'مرحله')
        ->set('type', 'select')
        ->set('optionsText', '')
        ->call('saveFieldDef')
        ->assertHasErrors(['optionsText' => 'required_if']);

    $this->assertDatabaseCount('disease_field_defs', 0);
});

test('select options preserve zero', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    $disease = Disease::factory()->create();

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('startCreateFieldDef')
        ->set('fieldKey', 'score')
        ->set('labelFa', 'امتیاز')
        ->set('type', 'select')
        ->set('optionsText', "0\n1")
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    $def = DiseaseFieldDef::query()
        ->where('disease_id', $disease->id)
        ->where('field_key', 'score')
        ->firstOrFail();

    expect($def->options)->toBe(['0', '1']);
});

test('options rejected for non select type', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    $disease = Disease::factory()->create();

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('startCreateFieldDef')
        ->set('fieldKey', 'stage')
        ->set('labelFa', 'مرحله')
        ->set('type', 'number')
        ->set('optionsText', "I\nII")
        ->call('saveFieldDef')
        ->assertHasErrors('optionsText');

    $this->assertDatabaseCount('disease_field_defs', 0);
});

test('field key is unique per disease', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    $first = Disease::factory()->create();
    $second = Disease::factory()->create();

    $component = Livewire::test('disease.manage')
        ->call('selectDisease', $first->id)
        ->call('startCreateFieldDef')
        ->set('fieldKey', 'sys')
        ->set('labelFa', 'فشار سیستولیک')
        ->set('type', 'number')
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    $component
        ->call('startCreateFieldDef')
        ->set('fieldKey', 'sys')
        ->set('labelFa', 'تکرار')
        ->set('type', 'number')
        ->call('saveFieldDef')
        ->assertHasErrors(['fieldKey' => 'unique']);

    $component
        ->call('selectDisease', $second->id)
        ->call('startCreateFieldDef')
        ->set('fieldKey', 'sys')
        ->set('labelFa', 'فشار سیستولیک')
        ->set('type', 'number')
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('disease_field_defs', ['disease_id' => $second->id, 'field_key' => 'sys']);
    $this->assertDatabaseCount('disease_field_defs', 2);
});

test('field key immutable on edit', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    $disease = Disease::factory()->create();
    $def = DiseaseFieldDef::query()->create([
        'disease_id' => $disease->id,
        'field_key' => 'sys',
        'label_fa' => 'فشار سیستولیک',
        'type' => 'number',
        'required' => false,
        'sort' => 0,
    ]);

    $component = Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('editFieldDef', $def->id)
        ->assertSet('fieldKey', 'sys')
        ->set('labelFa', 'فشار سیستولیک (ویرایش‌شده)')
        // A tampered payload must not rename a consumed JSON key either.
        ->set('fieldKey', 'hijacked')
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('disease_field_defs', [
        'id' => $def->id,
        'field_key' => 'sys',
        'label_fa' => 'فشار سیستولیک (ویرایش‌شده)',
    ]);
});

test('type select options render', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    $disease = Disease::factory()->create();

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->assertSee('عددی')
        ->assertSee('متنی')
        ->assertSee('انتخابی')
        ->assertSee('تاریخ');
});

test('route is gated by manage_diseases', function () {
    ['user' => $unauthorized] = $this->createUserWithUnit();
    $this->actingAs($unauthorized);

    $this->get('/disease/manage')->assertStatus(403);

    ['user' => $authorized] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($authorized);

    $this->get('/disease/manage')->assertStatus(200);
});

test('smoke created disease and field survive a fresh mount', function () {
    // §8.4 smoke test part 1: create a dummy disease + field through the
    // component (the same mutators the UI calls), then mount a FRESH instance
    // and read both back — proof the catalogue is data, not code.
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    Livewire::test('disease.manage')
        ->call('startCreateDisease')
        ->set('nameFa', 'بیماری نمایشی')
        ->set('slug', 'demo-disease')
        ->call('saveDisease')
        ->assertHasNoErrors();

    $disease = Disease::query()->where('slug', 'demo-disease')->firstOrFail();

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('startCreateFieldDef')
        ->set('fieldKey', 'demo_field')
        ->set('labelFa', 'فیلد نمایشی')
        ->set('type', 'text')
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('diseases', ['id' => $disease->id, 'name_fa' => 'بیماری نمایشی']);
    $this->assertDatabaseHas('disease_field_defs', [
        'disease_id' => $disease->id,
        'field_key' => 'demo_field',
        'label_fa' => 'فیلد نمایشی',
    ]);

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->assertSee('بیماری نمایشی')
        ->assertSee('فیلد نمایشی');
});
