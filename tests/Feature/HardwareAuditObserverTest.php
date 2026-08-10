<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

    $this->unit = Unit::create(['name' => 'واحد تست']);
    $this->person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);

    $this->user = User::factory()->create([
        'n_code' => '1234567890',
        'password' => Hash::make('password'),
    ]);
});

test('hardware audit observer records created action', function () {
    $hardware = Hardware::create([
        'n_code' => '1234567890',
        'pc_name' => 'AUDIT-PC',
        'type' => 'pc',
        'os' => 'Windows 11',
        'cpu' => 'Intel i5',
        'ram' => '8192',
        'hdd' => 'SSD 256GB',
        'mac' => '00:00:00:00:00:30',
    ]);

    $audit = $hardware->audits()->first();
    expect($audit)->not->toBeNull();
    expect($audit->action)->toBe('created');
});

test('hardware audit observer records updated action with changes', function () {
    $hardware = Hardware::create([
        'n_code' => '1234567890',
        'pc_name' => 'AUDIT-PC',
        'type' => 'pc',
        'os' => 'Windows 11',
        'cpu' => 'Intel i5',
        'ram' => '8192',
        'hdd' => 'SSD 256GB',
        'mac' => '00:00:00:00:00:31',
    ]);

    $hardware->update(['cpu' => 'Intel i7', 'ram' => '16384']);

    $audit = $hardware->audits()->where('action', 'updated')->first();
    expect($audit)->not->toBeNull();
    expect($audit->changes)->toBeArray();
    expect(collect($audit->changes)->firstWhere('field', 'cpu'))->not->toBeNull();
});

test('hardware audit observer records deleted action', function () {
    $hardware = Hardware::create([
        'n_code' => '1234567890',
        'pc_name' => 'AUDIT-PC',
        'type' => 'pc',
        'os' => 'Windows 11',
        'cpu' => 'Intel i5',
        'ram' => '8192',
        'hdd' => 'SSD 256GB',
        'mac' => '00:00:00:00:00:32',
    ]);

    $hardware->delete();

    $audit = $hardware->audits()->where('action', 'deleted')->first();
    expect($audit)->not->toBeNull();
});
