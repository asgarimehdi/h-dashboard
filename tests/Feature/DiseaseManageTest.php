<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
