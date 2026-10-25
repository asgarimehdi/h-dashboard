<?php

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\DiseaseFieldDef;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
uses(InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();
});

/**
 * @return array{0: User, 1: Unit}
 */
function diseaseGuardUser(): array
{
    ['user' => $user, 'unit' => $unit] = test()->createUserWithUnit(['manage_diseases']);
    test()->actingAs($user);

    return [$user, $unit];
}

test('delete disease with cases is blocked', function () {
    [$user, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $case = DiseaseCase::factory()->create([
        'disease_id' => $disease->id,
        'unit_id' => $unit->id,
        'subject_unit_id' => $unit->id,
    ]);

    Livewire::test('disease.manage')->call('deleteDisease', $disease->id);

    $this->assertDatabaseHas('diseases', ['id' => $disease->id]);
    $this->assertDatabaseHas('disease_cases', ['id' => $case->id]);
});

test('deactivate disease keeps cases and hides from new registrations', function () {
    [$user, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create(['is_active' => true]);
    $case = DiseaseCase::factory()->create([
        'disease_id' => $disease->id,
        'unit_id' => $unit->id,
        'subject_unit_id' => $unit->id,
    ]);

    Livewire::test('disease.manage')->call('toggleDiseaseActive', $disease->id);

    expect($disease->fresh()->is_active)->toBeFalse();
    $this->assertDatabaseHas('disease_cases', ['id' => $case->id]);
});

test('delete field def without data', function () {
    diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = DiseaseFieldDef::query()->create([
        'disease_id' => $disease->id,
        'field_key' => 'sys',
        'label_fa' => 'فشار سیستولیک',
        'type' => 'number',
    ]);

    Livewire::test('disease.manage')->call('deleteFieldDef', $def->id);

    $this->assertDatabaseMissing('disease_field_defs', ['id' => $def->id]);
});

test('delete field def with data is blocked', function () {
    [$user, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = DiseaseFieldDef::query()->create([
        'disease_id' => $disease->id,
        'field_key' => 'sys',
        'label_fa' => 'فشار سیستولیک',
        'type' => 'number',
    ]);
    DiseaseCase::factory()->create([
        'disease_id' => $disease->id,
        'unit_id' => $unit->id,
        'subject_unit_id' => $unit->id,
        'fields' => ['sys' => 140],
    ]);

    Livewire::test('disease.manage')->call('deleteFieldDef', $def->id);

    $this->assertDatabaseHas('disease_field_defs', ['id' => $def->id]);
});

test('stored json keys survive blocked delete', function () {
    [$user, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = DiseaseFieldDef::query()->create([
        'disease_id' => $disease->id,
        'field_key' => 'sys',
        'label_fa' => 'فشار سیستولیک',
        'type' => 'number',
    ]);
    $case = DiseaseCase::factory()->create([
        'disease_id' => $disease->id,
        'unit_id' => $unit->id,
        'subject_unit_id' => $unit->id,
        'fields' => ['sys' => 140],
    ]);

    Livewire::test('disease.manage')->call('deleteFieldDef', $def->id);

    expect($case->fresh()->fields)->toHaveKey('sys')
        ->and($case->fresh()->fields['sys'])->toBe(140);
});

test('field def data check is scoped to its disease', function () {
    [$user, $unit] = diseaseGuardUser();

    $diseaseWithCase = Disease::factory()->create();
    DiseaseCase::factory()->create([
        'disease_id' => $diseaseWithCase->id,
        'unit_id' => $unit->id,
        'subject_unit_id' => $unit->id,
        'fields' => ['x' => 1],
    ]);

    $otherDisease = Disease::factory()->create();
    $otherDef = DiseaseFieldDef::query()->create([
        'disease_id' => $otherDisease->id,
        'field_key' => 'x',
        'label_fa' => 'فیلد بیماری دیگر',
        'type' => 'number',
    ]);

    Livewire::test('disease.manage')->call('deleteFieldDef', $otherDef->id);

    $this->assertDatabaseMissing('disease_field_defs', ['id' => $otherDef->id]);
});

test('slug is immutable when disease has cases', function () {
    [$user, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create(['name_fa' => 'نام قبلی', 'slug' => 'locked-slug']);
    DiseaseCase::factory()->create([
        'disease_id' => $disease->id,
        'unit_id' => $unit->id,
        'subject_unit_id' => $unit->id,
    ]);

    $component = Livewire::test('disease.manage')
        ->call('editDisease', $disease->id)
        ->assertSet('slug', 'locked-slug')
        ->assertSee('پس از ثبت کیس، کلید بیماری قابل ویرایش نیست.');

    $component
        ->set('nameFa', 'نام تازه')
        ->set('slug', 'hijacked-slug')
        ->call('saveDisease')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('diseases', [
        'id' => $disease->id,
        'name_fa' => 'نام تازه',
        'slug' => 'locked-slug',
    ]);
});

test('mutators reauthorize after permission revoked', function () {
    [$user] = diseaseGuardUser();

    $component = Livewire::test('disease.manage')->assertStatus(200);

    $user->revokePermissionTo('manage_diseases');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component
        ->set('nameFa', 'بیماری غیرمجاز')
        ->set('slug', 'unauthorized-disease')
        ->call('saveDisease')
        ->assertForbidden();

    $this->assertDatabaseCount('diseases', 0);
});
