<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        $this->call([
            EstekhdamSeeder::class,
            TahsilSeeder::class,
            RadifSeeder::class,
            SematSeeder::class,
            PersonsTableSeeder::class,
            UsersTableSeeder::class,
            ProvinceSeeder::class,
            CountySeeder::class,
            UnitTypeSeeder::class,
            UnitTypeRelationshipSeeder::class,
            UnitSeeder::class,
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }
}
