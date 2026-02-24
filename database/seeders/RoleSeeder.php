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

        // ۱. ایجاد نقش مدیر کل و اختصاص تمام مجوزها
        $adminRole = Role::create(['name' => 'admin', 'label' => 'مدیر کل']);
        $adminRole->givePermissionTo(Permission::all());

        // ۲. ایجاد نقش مدیر واحد
        $managerRole = Role::create(['name' => 'unit_manager', 'label' => 'مدیر واحد']);
        $managerRole->givePermissionTo([
            'create_ticket',
            'manage_unit_tickets',
            'view_assigned_tickets',
            'organization'
        ]);

        // ۳. ایجاد نقش کارشناس واحد
        $expertRole = Role::create(['name' => 'expert', 'label' => 'کارشناس واحد']);
        $expertRole->givePermissionTo([
            'create_ticket',
            'view_assigned_tickets'
        ]);

        // ۴. ایجاد نقش کاربر عادی
        $userRole = Role::create(['name' => 'user', 'label' => 'کاربر']);
        $userRole->givePermissionTo([
            'create_ticket'
        ]);

        // Assign roles to demo users
        $superadmins = User::whereIn('id', [1, 2])->get();

        foreach($superadmins as $user){
            $user->assignRole('admin');
        }
    }
}
