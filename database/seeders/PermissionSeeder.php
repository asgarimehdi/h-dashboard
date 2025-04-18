<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            ['name' => 'create-role', 'description' => 'ایجاد نقش جدید'],
            ['name' => 'edit-role', 'description' => 'ویرایش نقش‌ها'],
            ['name' => 'delete-role', 'description' => 'حذف نقش‌ها'],
            ['name' => 'create-unit', 'description' => 'ایجاد واحد جدید'],
            ['name' => 'edit-unit', 'description' => 'ویرایش واحدها'],
            ['name' => 'delete-unit', 'description' => 'حذف واحدها'],
            ['name' => 'create-permission', 'description' => 'ایجاد مجوز جدید'],
            ['name' => 'edit-permission', 'description' => 'ویرایش مجوزها'],
            ['name' => 'delete-permission', 'description' => 'حذف مجوزها'],
            ['name' => 'create-permission-access-level', 'description' => 'ایجاد اتصال مجوز به دسترسی'],
            ['name' => 'edit-permission-access-level', 'description' => 'ویرایش اتصال مجوز به دسترسی'],
            ['name' => 'delete-permission-access-level', 'description' => 'حذف اتصال مجوز به دسترسی'],
            ['name' => 'create-access-level', 'description' => 'ایجاد دسترسی جدید'],
            ['name' => 'edit-access-level', 'description' => 'ویرایش دسترسی '],
            ['name' => 'delete-access-level', 'description' => 'حذف دسترسی'],
            ['name' => 'create-role-access-level', 'description' => 'ایجاد اتصال دسترسی به نقش'],
            ['name' => 'edit-role-access-level', 'description' => 'ویرایش اتصال دسترسی به نقش'],
            ['name' => 'delete-role-access-level', 'description' => 'حذف اتصال مجوزدسترسی به نقش'],
            ['name' => 'manage-users', 'description' => 'مدیریت کاربران'],
            ['name' => 'manage-kargozini', 'description' => 'مدیریت کارگزینی'],
            ['name' => 'manage-units', 'description' => 'مدیریت ساختار سازمان'],
            ['name' => 'manage-access', 'description' => 'مدیریت سطوح دسترسی'],
            ['name' => 'manage-maps', 'description' => 'مدیریت نقشه‌ها'],
        
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission['name']], $permission);
        }
    }
}