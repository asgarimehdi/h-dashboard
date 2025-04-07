<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            ['name' => 'Manager', 'description' => 'مدیر شبکه'],
            ['name' => 'It', 'description' => 'کارشناس فناوری اطلاعات '],
            ['name' => 'Accounting', 'description' => 'حسابدار '],
        ];

        foreach ($roles as $role) {
            Role::create($role);
        }
    }
}