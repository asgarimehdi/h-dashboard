<?php

namespace Database\Seeders;

//use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // create permissions
        Permission::create(['name' => 'kargozini','label'=>'کارگزینی']);
        Permission::create(['name' => 'map','label'=>'نقشه']);
        Permission::create(['name' => 'organization','label'=>'ساختار سازمانی']);
        Permission::create(['name' => 'op-cache','label'=>'دسترسی به کش سرور']);

        // update cache to know about the newly created permissions (required if using WithoutModelEvents in seeders)
        app()[PermissionRegistrar::class]->forgetCachedPermissions();


    }
}
