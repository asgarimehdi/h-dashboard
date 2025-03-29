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
        ];

        foreach ($permissions as $permission) {
            Permission::create($permission);
        }
    }
}