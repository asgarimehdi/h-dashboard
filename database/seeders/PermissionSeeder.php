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
            ['name' => 'create-permission-role', 'description' => 'ایجاد اتصال مجوز به نقش'],
            ['name' => 'edit-permission-role', 'description' => 'ویرایش اتصال مجوز به نقش'],
            ['name' => 'delete-permission-role', 'description' => 'حذف اتصال مجوز به نقش'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission['name']], $permission);
        }
    }
}
