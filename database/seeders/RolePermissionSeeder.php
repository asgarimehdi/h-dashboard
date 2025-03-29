<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;

class RolePermissionSeeder extends Seeder
{
    public function run()
    {
        $user1 = User::firstOrCreate(['n_code' => '4400176134'], ['password' => bcrypt('password')]);
        $user2 = User::firstOrCreate(['n_code' => '4411015056'], ['password' => bcrypt('password')]);

        // پیدا کردن نقش‌ها
        $admin = Role::where('name', 'admin')->first();
        $editor = Role::where('name', 'editor')->first();

        // اتصال کاربر به نقش
        $user1->roles()->sync([$admin->id]);
        $user2->roles()->sync([$admin->id]);

        // پیدا کردن مجوزها
        $permissions = Permission::whereIn('name', [
            'create-role',
            'edit-role',
            'delete-role',
            'create-unit',
            'edit-unit',
            'delete-unit'
        ])->pluck('id');

        // اتصال مجوزها به نقش admin
        $admin->permissions()->sync($permissions);

        // اتصال مجوزهای محدود به editor
        $editorPermissions = Permission::whereIn('name', [
            'create-unit',
            'edit-unit'
        ])->pluck('id');
        $editor->permissions()->sync($editorPermissions);
    }
}