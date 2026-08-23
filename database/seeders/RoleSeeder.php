<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ۱. ایجاد نقش مدیر کل و اختصاص تمام مجوزها
        // firstOrCreate: PermissionSeeder may already have created `admin`
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['label' => 'مدیر کل']
        );
        $adminRole->syncPermissions(Permission::all());

        // ۲. ایجاد نقش مدیر واحد
        $managerRole = Role::firstOrCreate(
            ['name' => 'unit_manager', 'guard_name' => 'web'],
            ['label' => 'مدیر واحد']
        );
        $managerRole->syncPermissions([
            'create_ticket',
            'manage_unit_tickets',
            'view_assigned_tickets',
            'organization',
        ]);

        // ۳. ایجاد نقش کارشناس واحد
        $expertRole = Role::firstOrCreate(
            ['name' => 'expert', 'guard_name' => 'web'],
            ['label' => 'کارشناس واحد']
        );
        $expertRole->syncPermissions([
            'create_ticket',
            'view_assigned_tickets',
        ]);

        // ۴. ایجاد نقش کاربر عادی
        $userRole = Role::firstOrCreate(
            ['name' => 'user', 'guard_name' => 'web'],
            ['label' => 'کاربر']
        );
        $userRole->syncPermissions([
            'create_ticket',
        ]);

        // Assign roles to demo users
        $superadmins = User::whereIn('id', [1, 2, 3])->get();

        foreach ($superadmins as $user) {
            $user->assignRole('admin');
        }
    }
}
