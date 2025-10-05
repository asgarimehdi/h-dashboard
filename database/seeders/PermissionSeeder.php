<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'role.create',
            'role.edit',
            'role.delete',
            'unit.create',
            'unit.edit',
            'unit.delete',
            'permission.create',
            'permission.edit',
            'permission.delete',
            'permission-access-level.create',
            'permission-access-level.edit',
            'permission-access-level.delete',
            'access-level.create',
            'access-level.edit',
            'access-level.delete',
            'role-access-level.create',
            'role-access-level.edit',
            'role-access-level.delete',
            'users.manage',
            'kargozini.manage',
            'units.manage',
            'access.manage',
            'maps.manage',

        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
