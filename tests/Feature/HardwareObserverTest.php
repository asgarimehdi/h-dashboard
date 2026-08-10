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

test('hardware created event persists and audit entry is created', function () {
    $hardware = Hardware::create([
        'n_code' => '1234567890',
        'pc_name' => 'OBS-PC',
        'type' => 'pc',
        'os' => 'Windows 11',
        'cpu' => 'Intel i5',
        'ram' => '8192',
        'hdd' => 'SSD 256GB',
        'mac' => '00:00:00:00:00:20',
    ]);

    expect(Hardware::find($hardware->id))->not->toBeNull();
    expect($hardware->audits()->count())->toBe(1);
});

test('hardware updated event creates audit diff', function () {
    $hardware = Hardware::create([
        'n_code' => '1234567890',
        'pc_name' => 'OBS-PC',
        'type' => 'pc',
        'os' => 'Windows 11',
        'cpu' => 'Intel i5',
        'ram' => '8192',
        'hdd' => 'SSD 256GB',
        'mac' => '00:00:00:00:00:21',
    ]);

    $hardware->update(['cpu' => 'Intel i7']);

    $updatedAudit = $hardware->audits()->where('action', 'updated')->first();
    expect($updatedAudit)->not->toBeNull();
    expect($updatedAudit->changes)->toBeArray();
    expect(collect($updatedAudit->changes)->firstWhere('field', 'cpu'))->not->toBeNull();
});

test('hardware deleted event creates audit entry', function () {
    $hardware = Hardware::create([
        'n_code' => '1234567890',
        'pc_name' => 'OBS-PC',
        'type' => 'pc',
        'os' => 'Windows 11',
        'cpu' => 'Intel i5',
        'ram' => '8192',
        'hdd' => 'SSD 256GB',
        'mac' => '00:00:00:00:00:22',
    ]);

    $hardware->delete();

    expect(Hardware::find($hardware->id))->toBeNull();
    expect($hardware->audits()->where('action', 'deleted')->exists())->toBeTrue();
});
