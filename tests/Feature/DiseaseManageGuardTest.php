<?php

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\DiseaseFieldDef;
use App\Models\Unit;
use App\Models\User;
use App\Services\CacheInvalidationServiceInterface;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithAtomicToggles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
uses(InteractsWithTestSetup::class, InteractsWithAtomicToggles::class);

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

// ==================== #1849 — atomic toggleDiseaseActive ====================

test('toggleDiseaseActive uses the locked row, not the stale model', function () {
    // Another writer flipped the row to `true` before our lock. Deriving the new
    // value from the stale model (false) writes `true` and loses our toggle; the
    // locked read must be the source of truth.
    diseaseGuardUser();

    $disease = Disease::factory()->create(['is_active' => false]);

    $this->fakeLockedRow((object) ['id' => $disease->id, 'is_active' => true]);

    Livewire::test('disease.manage')->call('toggleDiseaseActive', $disease->id);

    expect((bool) $disease->fresh()->is_active)->toBeFalse();
});

test('toggleDiseaseActive reports a vanished disease without writing', function () {
    diseaseGuardUser();

    $disease = Disease::factory()->create(['is_active' => true]);

    $this->fakeLockedRow(null);

    $component = Livewire::test('disease.manage')->call('toggleDiseaseActive', $disease->id);

    expect((bool) $disease->fresh()->is_active)->toBeTrue()
        ->and($this->toastType($component))->toBe('error')
        ->and($this->toastTitle($component))->toBe('این بیماری دیگر وجود ندارد.');
});

test('double toggleDiseaseActive returns to the starting value', function () {
    // Sanity only: two sequential flips also return to the starting value on the
    // buggy read-then-write code, so this is NOT the race guard — the
    // locked-row test above is.
    diseaseGuardUser();

    $disease = Disease::factory()->create(['is_active' => false]);

    Livewire::test('disease.manage')->call('toggleDiseaseActive', $disease->id);
    expect((bool) $disease->fresh()->is_active)->toBeTrue();

    Livewire::test('disease.manage')->call('toggleDiseaseActive', $disease->id);
    expect((bool) $disease->fresh()->is_active)->toBeFalse();
});

test('toggleDiseaseActive toast describes the stored value', function () {
    diseaseGuardUser();

    $disease = Disease::factory()->create(['is_active' => true, 'name_fa' => 'آنفلوانزا']);

    $component = Livewire::test('disease.manage')->call('toggleDiseaseActive', $disease->id);

    expect((bool) $disease->fresh()->is_active)->toBeFalse()
        ->and($this->toastTitle($component))->toBe('«آنفلوانزا» غیرفعال شد');
});

test('toggleDiseaseActive bumps the disease_catalog namespace', function () {
    // The write must go through the model: a query-builder mass update fires no
    // `Disease::updated`, so the catalogue cache would silently go stale.
    diseaseGuardUser();

    $disease = Disease::factory()->create(['is_active' => true]);

    $cache = app(CacheInvalidationServiceInterface::class);
    $before = $cache->getVersion('disease_catalog');

    Livewire::test('disease.manage')->call('toggleDiseaseActive', $disease->id);

    expect($cache->getVersion('disease_catalog'))->toBeGreaterThan($before);
});

/**
 * MaryUI's toast is an `xjs` effect, not a validation error or a flash the
 * Pest TestCase can read; decode its title so a test can pin the message.
 */
function diseaseGuardToastTitle($component): ?string
{
    foreach ($component->effects['xjs'] ?? [] as $effect) {
        $expression = $effect['expression'] ?? '';

        if (str_starts_with($expression, 'toast(')) {
            $decoded = json_decode(substr($expression, 6, -1), true);

            return $decoded['toast']['title'] ?? null;
        }
    }

    return null;
}

/** @param array<string, mixed> $attributes */
function diseaseGuardDef(Disease $disease, array $attributes): DiseaseFieldDef
{
    return DiseaseFieldDef::query()->create(array_merge([
        'disease_id' => $disease->id,
        'label_fa' => 'فیلد آزمایشی',
        'type' => 'text',
    ], $attributes));
}

/** @param array<string, mixed> $fields */
function diseaseGuardCase(Disease $disease, Unit $unit, array $fields): DiseaseCase
{
    return DiseaseCase::factory()->create([
        'disease_id' => $disease->id,
        'unit_id' => $unit->id,
        'subject_unit_id' => $unit->id,
        'fields' => $fields,
    ]);
}

test('changing a field type is blocked when stored values would fail', function () {
    [, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = diseaseGuardDef($disease, ['field_key' => 'stage', 'type' => 'select', 'options' => ['I', 'II']]);
    diseaseGuardCase($disease, $unit, ['stage' => 'II']);

    $component = Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('editFieldDef', $def->id)
        ->set('type', 'number')
        ->set('optionsText', '')
        ->call('saveFieldDef');

    expect($def->fresh()->type)->toBe('select')
        ->and($def->fresh()->options)->toBe(['I', 'II'])
        ->and(diseaseGuardToastTitle($component))
        ->toBe('این فیلد برای بعضی کیس‌ها مقدار ثبت‌شده دارد که با تعریف جدید سازگار نیست؛ تغییر نوع، گزینه‌ها یا الزامی بودن ممکن نیست.');
});

test('removing a select option that a case uses is blocked', function () {
    [, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = diseaseGuardDef($disease, ['field_key' => 'stage', 'type' => 'select', 'options' => ['I', 'II', 'III']]);
    diseaseGuardCase($disease, $unit, ['stage' => 'II']);

    $component = Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('editFieldDef', $def->id)
        ->set('optionsText', "I\nIII")
        ->call('saveFieldDef');

    expect($def->fresh()->options)->toBe(['I', 'II', 'III'])
        ->and(diseaseGuardToastTitle($component))->toContain('سازگار نیست');
});

test('removing an unused select option is allowed while other cases hold values', function () {
    [, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = diseaseGuardDef($disease, ['field_key' => 'stage', 'type' => 'select', 'options' => ['I', 'II', 'III']]);
    diseaseGuardCase($disease, $unit, ['stage' => 'I']);

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('editFieldDef', $def->id)
        ->set('optionsText', "I\nII")
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    expect($def->fresh()->options)->toBe(['I', 'II']);
});

test('making a field required is blocked when a case has no value', function () {
    [, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = diseaseGuardDef($disease, ['field_key' => 'note', 'type' => 'text', 'required' => false]);
    diseaseGuardCase($disease, $unit, []);

    $component = Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('editFieldDef', $def->id)
        ->set('isRequired', true)
        ->call('saveFieldDef');

    expect($def->fresh()->required)->toBeFalse()
        ->and(diseaseGuardToastTitle($component))->toContain('سازگار نیست');
});

test('making a field required is allowed when every case has a value', function () {
    [, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = diseaseGuardDef($disease, ['field_key' => 'sys', 'type' => 'number', 'required' => false]);
    diseaseGuardCase($disease, $unit, ['sys' => 140]);

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('editFieldDef', $def->id)
        ->set('isRequired', true)
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    expect($def->fresh()->required)->toBeTrue();
});

test('a type change whose stored values still validate is allowed', function () {
    [, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = diseaseGuardDef($disease, ['field_key' => 'sys', 'type' => 'text']);
    diseaseGuardCase($disease, $unit, ['sys' => '140']);

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('editFieldDef', $def->id)
        ->set('type', 'number')
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    expect($def->fresh()->type)->toBe('number');
});

test('editing only the label is allowed while cases hold values', function () {
    [, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = diseaseGuardDef($disease, ['field_key' => 'sys', 'type' => 'number']);
    diseaseGuardCase($disease, $unit, ['sys' => 140]);

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('editFieldDef', $def->id)
        ->set('labelFa', 'قند خون')
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    expect($def->fresh()->label_fa)->toBe('قند خون')
        ->and($def->fresh()->type)->toBe('number');
});

test('a pre-existing invalid case does not block an unrelated type change', function () {
    [, $unit] = diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = diseaseGuardDef($disease, ['field_key' => 'note', 'type' => 'text', 'required' => true]);
    diseaseGuardCase($disease, $unit, []);

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('editFieldDef', $def->id)
        ->set('type', 'number')
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    expect($def->fresh()->type)->toBe('number');
});

test('a type change is allowed when the disease has no cases', function () {
    diseaseGuardUser();

    $disease = Disease::factory()->create();
    $def = diseaseGuardDef($disease, ['field_key' => 'note', 'type' => 'text']);

    Livewire::test('disease.manage')
        ->call('selectDisease', $disease->id)
        ->call('editFieldDef', $def->id)
        ->set('type', 'number')
        ->call('saveFieldDef')
        ->assertHasNoErrors();

    expect($def->fresh()->type)->toBe('number');
});
