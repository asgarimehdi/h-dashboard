<?php

namespace Tests\Feature;

use App\Imports\HardwareImport;
use App\Models\Estekhdam;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(HardwareImport::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->unit = Unit::create(['name' => 'واحد تست']);
    $this->semat = Semat::create(['name' => 'تکنسین']);
    $this->tahsil = Tahsil::create(['name' => 'لیسانس']);
    $this->estekhdam = Estekhdam::create(['name' => 'رسمی']);
    $this->radif = Radif::create(['name' => 'ردیف 1']);

    $this->person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'احمد',
        'l_name' => 'محمدی',
        'u_id' => $this->unit->id,
        's_id' => $this->semat->id,
        't_id' => $this->tahsil->id,
        'e_id' => $this->estekhdam->id,
        'r_id' => $this->radif->id,
    ]);

    $this->user = User::factory()->create(['n_code' => $this->person->n_code]);
    $this->user->givePermissionTo('manage_hardware');
    $this->user->givePermissionTo('kargozini');
    // ایشو #814: import پرسنل داخل کامپوننت هم `manage_personnel` می‌خواهد.
    $this->user->givePermissionTo('manage_personnel');

    Session::put('current_unit_id', $this->unit->id);
});

test('hardware import component shows preview and confirms import', function () {
    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    Livewire::actingAs($this->user)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview')
        ->assertSet('showPreview', true)
        ->assertSet('importStats.total', 1)
        ->call('confirmImport');

    $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-NEW']);
});

test('person import component shows preview and confirms import', function () {
    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    Livewire::actingAs($this->user)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview')
        ->assertSet('showPreview', true)
        ->assertSet('importStats.total', 1)
        ->call('confirmImport');

    $this->assertDatabaseHas('persons', ['n_code' => '9876543210', 'u_id' => $this->unit->id]);
});

test('import components are protected by RBAC', function () {
    $guestUser = User::factory()->create(); // No permissions

    // Test hardware import route middleware
    $this->actingAs($guestUser)
        ->get('/hardware/import')
        ->assertStatus(403);

    // Test persons import route middleware
    $this->actingAs($guestUser)
        ->get('/kargozini/persons/import')
        ->assertStatus(403);
});

// ایشو #814: لایه‌ی دوم مجوز داخل خودِ کامپوننت — دارنده‌ی صرفِ
// `kargozini` (بدون `manage_personnel`) نه پیش‌نمایش می‌گیرد، نه تایید می‌کند.
test('person import requires manage_personnel inside the component', function () {
    $kargoziniUser = User::factory()->create();
    $kargoziniUser->givePermissionTo('kargozini');

    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    Livewire::actingAs($kargoziniUser)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview')
        ->assertForbidden();

    Livewire::actingAs($kargoziniUser)
        ->test('kargozini.import-persons.import-persons')
        ->call('confirmImport')
        ->assertForbidden();

    $this->assertDatabaseMissing('persons', ['n_code' => '9876543210']);
});

// ایشو #814: همان لایه‌ی دوم برای import سخت‌افزار با مجوزِ گیتِ مسیرش.
test('hardware import requires manage_hardware inside the component', function () {
    $readOnlyUser = User::factory()->create();
    $readOnlyUser->givePermissionTo('kargozini');

    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    Livewire::actingAs($readOnlyUser)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview')
        ->assertForbidden();

    Livewire::actingAs($readOnlyUser)
        ->test('hardware.import-hardware.import-hardware')
        ->call('confirmImport')
        ->assertForbidden();

    $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-NEW']);
});
