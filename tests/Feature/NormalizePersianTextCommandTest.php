<?php

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use App\\Models\\Hardware;
use App\\Models\\Person;

uses(RefreshDatabase::class);

test('normalize:persian-text command normalizes Arabic characters in database', function () {
    // Seed a Person with Arabic Yeh in f_name
    $person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'علي', // Arabic Yeh
        'l_name' => 'احمدي', // Arabic Yeh
        'u_id' => 1,
        'semat_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
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
        'pc_name' => 'کامپیوتر',
    ]);
});
