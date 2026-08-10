<?php

use App\Models\Hardware;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('normalize:persian-text command normalizes Arabic characters in database', function () {
    // Seed lookup tables required by Person FK constraints
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    $unitId = DB::table('units')->insertGetId(['name' => 'Test Unit']);

    // Seed a Person with Arabic Yeh in f_name
    $person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'علي', // Arabic Yeh
        'l_name' => 'احمدي', // Arabic Yeh
        'u_id' => $unitId,
        's_id' => $sId,
        't_id' => $tId,
        'e_id' => $eId,
        'r_id' => $rId,
    ]);

    // Seed Hardware with Arabic Kaf in pc_name
    Hardware::create([
        'n_code' => '1234567890',
        'pc_name' => 'كمپيوتر', // Arabic Kaf and Yeh
        'type' => 'Laptop',
        'os' => 'Windows',
        'ip_valid' => '1.1.1.1',
        'ip_local' => '192.168.1.1',
        'mac' => '00:00:00:00:00:00',
    ]);

    // Run the command
    $this->artisan('normalize:persian-text')
        ->assertExitCode(0);

    // Assert Person is normalized
    $this->assertDatabaseHas('persons', [
        'n_code' => '1234567890',
        'f_name' => 'علی',
        'l_name' => 'احمدی',
    ]);

    // Assert Hardware is normalized
    $this->assertDatabaseHas('hardwares', [
        'n_code' => '1234567890',
        'pc_name' => 'کمپیوتر',
    ]);
});
