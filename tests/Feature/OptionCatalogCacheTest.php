<?php

use App\Models\Disease;
use App\Models\Unit;
use App\Models\UnitType;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    ['user' => $this->actor, 'unit' => $this->actorUnit] = $this->createUserWithUnit([
        'manage_diseases',
        'manage_hardware',
    ]);

    $this->actingAs($this->actor);
});

/**
 * Count SQL statements whose exact select list matches $sqlPrefix, and return a
 * reader. The select list is the discriminator: the case list eager-loads
 * `disease:id,name_fa` / `subjectUnit:id,name`, so a warm render DOES read both
 * tables with a 2-column select and must not be counted here.
 */
function option_cache_reads(string $sqlPrefix): Closure
{
    $reads = 0;

    DB::listen(function ($query) use (&$reads, $sqlPrefix): void {
        if (str_starts_with($query->sql, $sqlPrefix)) {
            $reads++;
        }
    });

    return static function () use (&$reads): int {
        return $reads;
    };
}

test('a warm disease-index search render does not re-read unit options', function () {
    $reads = option_cache_reads('select "id", "name", "is_active" from "units"');

    $component = Livewire::test('disease.index');
    $cold = $reads();
    expect($cold)->toBeGreaterThan(0);

    $component->set('search', 'فشار');

    expect($reads())->toBe($cold);
});

test('a warm maintenance render does not re-read unit options', function () {
    $reads = option_cache_reads('select "id", "name", "is_active" from "units"');

    $component = Livewire::test('maintenance.index');
    $cold = $reads();
    expect($cold)->toBeGreaterThan(0);

    $component->set('search', 'تعمیر');

    expect($reads())->toBe($cold);
});

test('a warm disease-index search render does not re-read the disease catalogue', function () {
    $reads = option_cache_reads('select "id", "name_fa", "is_active" from "diseases"');

    $component = Livewire::test('disease.index');
    $cold = $reads();
    expect($cold)->toBeGreaterThan(0);

    $component->set('search', 'فشار');

    expect($reads())->toBe($cold);
});

test('a warm disease-create re-render does not re-read the disease catalogue', function () {
    $reads = option_cache_reads('select "id", "name_fa", "is_active" from "diseases"');

    $component = Livewire::test('disease.create');
    $cold = $reads();
    expect($cold)->toBeGreaterThan(0);

    $component->call('$refresh');

    expect($reads())->toBe($cold);
});

test('the disease unit filter excludes an inactive base unit but keeps active descendants', function () {
    $child = Unit::factory()->create(['name' => 'واحد فعال ۱۷۶۱', 'parent_id' => $this->actorUnit->id]);
    Unit::factory()->create(['name' => 'واحد بیرون ۱۷۶۱']);

    $this->actorUnit->update(['name' => 'واحد خودی ۱۷۶۱', 'is_active' => false]);

    $labels = array_column(Livewire::test('disease.index')->instance()->unitOptions(), 'label');

    expect($labels)->toContain($child->name)
        ->and($labels)->not->toContain('واحد خودی ۱۷۶۱')
        ->and($labels)->not->toContain('واحد بیرون ۱۷۶۱');
});

test('the maintenance unit picker keeps an inactive base unit', function () {
    $this->actorUnit->update(['name' => 'واحد خودی ۱۷۶۲', 'is_active' => false]);

    Livewire::test('maintenance.index')
        ->assertViewHas('unitOptions', fn ($options) => collect($options)->contains('label', 'واحد خودی ۱۷۶۲'));
});

test('a unit written after the cache is warm appears on the next disease render', function () {
    $component = Livewire::test('disease.index');

    Unit::create(['name' => 'واحد تازه ۱۷۶۱', 'parent_id' => $this->actorUnit->id]);

    $labels = array_column($component->call('$refresh')->instance()->unitOptions(), 'label');

    expect($labels)->toContain('واحد تازه ۱۷۶۱');
});

test('a unit written after the cache is warm appears on the next maintenance render', function () {
    $component = Livewire::test('maintenance.index');

    Unit::create(['name' => 'واحد تازه ۱۷۶۲', 'parent_id' => $this->actorUnit->id]);

    $component->call('$refresh')
        ->assertViewHas('unitOptions', fn ($options) => collect($options)->contains('label', 'واحد تازه ۱۷۶۲'));
});

test('a warmed wide scope never leaks unit options into a narrower scope', function () {
    ['user' => $wide, 'unit' => $wideUnit] = $this->createUserWithUnit(['manage_diseases']);
    ['user' => $narrow, 'unit' => $narrowUnit] = $this->createUserWithUnit(['manage_diseases']);

    $wideUnit->update(['name' => 'واحد گسترده ۱۷۶۱']);
    $narrowUnit->update(['name' => 'واحد محدود ۱۷۶۱']);

    $this->actingAs($wide);
    Session::put('current_unit_id', $wideUnit->id);
    Livewire::test('disease.index')->assertSee('واحد گسترده ۱۷۶۱');

    $this->actingAs($narrow);
    Session::put('current_unit_id', $narrowUnit->id);
    Livewire::test('disease.index')
        ->assertSee('واحد محدود ۱۷۶۱')
        ->assertDontSee('واحد گسترده ۱۷۶۱');
});

test('an empty scope does not inherit a warmed wider scope', function () {
    Livewire::test('disease.index')->assertSee($this->actorUnit->name);

    $this->actor->units()->detach();
    $this->actor->person?->update(['u_id' => null]);
    Session::forget('current_unit_id');
    app(AccessService::class)->clearCache($this->actor);

    Livewire::test('disease.index')
        ->assertSet('emptyScope', true)
        ->assertDontSee($this->actorUnit->name);
});

test('a disease write invalidates the catalogue and the two pickers stay separate', function () {
    $index = Livewire::test('disease.index');
    $create = Livewire::test('disease.create');

    $active = Disease::factory()->create(['name_fa' => 'بیماری تازه ۱۷۶۱']);
    $retired = Disease::factory()->create(['name_fa' => 'بیماری بازنشسته ۱۷۶۱', 'is_active' => false]);

    $index->call('$refresh');
    $create->call('$refresh');

    $indexValues = array_column($index->instance()->diseaseOptions(), 'value');
    $createValues = array_column($create->instance()->diseaseOptions(), 'value');

    expect($indexValues)->toContain($active->id)
        ->and($indexValues)->toContain($retired->id);
    expect($createValues)->toContain($active->id)
        ->and($createValues)->not->toContain($retired->id);
});

test('a disease write bumps the disease_catalog namespace', function () {
    $this->assertCacheInvalidated('disease_catalog', fn () => Disease::factory()->create());
});

test('a warm disease-index unit picker does not re-read units while the modal is open', function () {
    $reads = option_cache_reads('select "id", "name", "parent_id", "unit_type_id" from "units"');

    $component = Livewire::test('disease.index');
    $component->set('unitModal', true);          // cold: picker builds
    $cold = $reads();
    expect($cold)->toBeGreaterThan(0);

    $component->call('$refresh');                // warm: modal still open
    expect($reads())->toBe($cold);
});

test('a warm disease-create unit picker does not re-read units while the modal is open', function () {
    $reads = option_cache_reads('select "id", "name", "parent_id", "unit_type_id" from "units"');

    $component = Livewire::test('disease.create');
    $component->set('unitModal', true);
    $cold = $reads();
    expect($cold)->toBeGreaterThan(0);

    $component->call('$refresh');
    expect($reads())->toBe($cold);
});

test('a unit written while the picker is warm appears on the next picker render', function () {
    $component = Livewire::test('disease.index')->set('unitModal', true);

    Unit::create(['name' => 'واحد تازه ۱۹۵۵', 'parent_id' => $this->actorUnit->id]);

    $names = array_column($component->call('$refresh')->instance()->unitTree(), 'name');
    expect($names)->toContain('واحد تازه ۱۹۵۵');
});

test('a unit-type rename is reflected in the picker rows', function () {
    $type = UnitType::create(['name' => 'نوع الف ۱۹۵۵']);
    $unit = Unit::factory()->create([
        'name' => 'واحد نوع‌دار ۱۹۵۵',
        'parent_id' => $this->actorUnit->id,
        'unit_type_id' => $type->id,
    ]);

    $component = Livewire::test('disease.index')->set('unitModal', true);

    $type->update(['name' => 'نوع ب ۱۹۵۵']);

    $rows = collect($component->call('$refresh')->instance()->unitTree());
    expect($rows->firstWhere('id', $unit->id)['unit_type_name'])->toBe('نوع ب ۱۹۵۵');
});
