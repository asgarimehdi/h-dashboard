<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            ['name' => 'admin', 'description' => 'مدیر کل سیستم'],
            ['name' => 'editor', 'description' => 'ویرایشگر محتوا'],
            ['name' => 'viewer', 'description' => 'فقط مشاهده‌کننده'],
        ];

        foreach ($roles as $role) {
            Role::create($role);
        }
    }
}