<?php

namespace Database\Seeders;

//use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // create permissions
       // Role::create(['name' => 'gostaresh'])>givePermissionTo('organization');


        // or may be done by chaining
       // Role::create(['name' => 'it'])->givePermissionTo(['organization', 'op-cache']);

        Role::create(['name' => 'admin','label' => 'مدیر کل'])->givePermissionTo(Permission::all());

        // Assign roles to demo users
        $superadmins = User::where('id', 1)->orwhere('id', 2)->get();

//
        foreach($superadmins as $user){
            $user->assignRole('admin');
        }

    }
}
