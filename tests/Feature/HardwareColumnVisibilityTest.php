<?php

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeColVisUser(): array
{
    $unit = Unit::create(['name' => 'Col Unit']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create(['n_code' => $nCode, 'f_name' => 'C', 'l_name' => 'V', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);
    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    Permission::firstOrCreate(['name' => 'manage_hardware']);
    $user->givePermissionTo('manage_hardware');
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    Hardware::create([
        'n_code' => $nCode,
        'pc_name' => 'COL-PC-001',
        'type' => 'pc',
        'cpu' => 'Intel i5',
        'ram' => '8192',
    ]);

    return [$user];
}

function mainTableHtml(string $html): string
{
    // Anchor on maryUI's table (x-ref="headers"); help-content examples also
    // contain literal "<thead"/"<tbody" markup elsewhere on the page.
    $pos = 0;
    while (($start = strpos($html, '<thead', $pos)) !== false) {
        if (str_contains(substr($html, $start, 300), 'x-ref="headers"')) {
            return substr($html, $start);
        }

        $pos = $start + 6;
    }

    return '';
}

function theadOf(string $html): string
{
    return Str::between(mainTableHtml($html), '<thead', '</thead>');
}

it('all column toggles remove their column from the table header', function () {
    [$user] = makeColVisUser();

    $component = Livewire::actingAs($user)->test('hardware.index');

    // All toggleable columns render in thead by default
    $thead = theadOf($component->html());
    foreach (['نوع', 'OS', 'CPU', 'RAM', 'HDD', 'IP', 'وضعیت'] as $label) {
        expect($thead)->toContain($label);
    }

    // Uncheck each column -> its th disappears from thead, others remain
    foreach (['type' => 'نوع', 'os' => 'OS', 'cpu' => 'CPU', 'ram' => 'RAM', 'hdd' => 'HDD', 'ip_local' => 'IP', 'status' => 'وضعیت'] as $key => $label) {
        $component->set("visibleCols.$key", false);

        $thead = theadOf($component->html());
        expect($thead)->not->toContain($label);

        // Toggling back restores it
        $component->set("visibleCols.$key", true);
        expect(theadOf($component->html()))->toContain($label);
    }
});

it('hidden columns are skipped for body cells too so rows stay aligned', function () {
    [$user] = makeColVisUser();

    $component = Livewire::actingAs($user)->test('hardware.index');
    $defaultCells = substr_count(Str::between(mainTableHtml($component->html()), '<tbody', '</tbody>'), '<td');

    $component->set('visibleCols.cpu', false);
    $hiddenCells = substr_count(Str::between(mainTableHtml($component->html()), '<tbody', '</tbody>'), '<td');

    expect($hiddenCells)->toBeLessThan($defaultCells);
});
