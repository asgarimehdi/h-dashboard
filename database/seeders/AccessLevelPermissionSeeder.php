<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use App\Models\AccessLevel;
use Mary\View\Components\Accordion;

class AccessLevelPermissionSeeder extends Seeder
{
    public function run()
    {
        $user1 = User::firstOrCreate(['n_code' => '4400176134'], ['password' => bcrypt('password')]);
        $user2 = User::firstOrCreate(['n_code' => '4411015056'], ['password' => bcrypt('password')]);

        // پیدا کردن نقش‌ها
        $admin = AccessLevel::where('name', 'admin')->first();
        $editor = AccessLevel::where('name', 'editor')->first();
        $Manager = Role::where('name', 'Manager')->first();
        $It = Role::where('name', 'It')->first();
        // اتصال کاربر به نقش
        $user1->roles()->sync([$Manager->id]);
        $user2->roles()->sync([$It->id]);

        // پیدا کردن مجوزها
        $permissions = Permission::whereIn('name', [
            'create-role',
            'edit-role',
            'delete-role',
            'create-unit',
            'edit-unit',
            'delete-unit',
            'create-permission',
            'edit-permission',
            'delete-permission',
            'create-permission-access-level',
            'edit-permission-access-level',
            'delete-permission-access-level',
            'create-access-level',
            'edit-access-level',
            'delete-access-level',

        ])->pluck('id');

        // اتصال مجوزها به سطح دسترسی admin
        $admin->permissions()->sync($permissions);

        // اتصال مجوزهای محدود به سطح دسترسی editor
        $editorPermissions = Permission::whereIn('name', [
            'create-unit',
            'edit-unit'
        ])->pluck('id');
        $editor->permissions()->sync($editorPermissions);

        $Manager->accesslevels()->sync($admin);
        $It->accesslevels()->sync($admin);
    }
}