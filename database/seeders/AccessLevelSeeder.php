<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AccessLevel;

class AccessLevelSeeder extends Seeder
{
    public function run()
    {
        $access_levels = [
            ['name' => 'admin', 'description' => 'مدیر کل '],
            ['name' => 'editor', 'description' => 'ویرایشگر محتوا'],
            ['name' => 'viewer', 'description' => 'فقط مشاهده‌کننده'],
        ];

        foreach ($access_levels as $access_level) {
            AccessLevel::create($access_level);
        }
    }
}