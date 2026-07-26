<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
// use Illuminate\Database\\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\DB;

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
            BoundarySeeder::class,
            EstekhdamSeeder::class,
            TahsilSeeder::class,
            RadifSeeder::class,
            SematSeeder::class,
            PersonsTableSeeder::class,
            UsersTableSeeder::class,
            RegionSeeder::class,
            UnitTypeSeeder::class,
            UnitTypeRelationshipSeeder::class,
            UnitSeeder::class,
            UnitCapabilitySeeder::class,
            UserUnitSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
            PersonUserFromDeviceSeeder::class,
            HardwareSeeder::class,
            TodoSeeder::class,
            ActivityLogSeeder::class,
            TicketSeeder::class,
            PersonUserSeeder::class,
            CenterBoundarySeeder::class,
            HealthHouseBoundarySeeder::class,
        ]);

        // Reset PostgreSQL sequences after seeding to prevent unique constraint errors
        // when manually inserted IDs differ from auto-increment values.
        if (DB::getDriverName() === 'pgsql') {
            $this->resetPostgresSequences();
        }
    }

    /**
     * Reset all PostgreSQL sequence counters to the current MAX(id) + 1.
     * Prevents "duplicate key violates unique constraint" errors on manual ID inserts.
     */
    protected function resetPostgresSequences(): void
    {
        $tables = DB::select("
            SELECT tablename, attname
            FROM pg_index i
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
            JOIN pg_class c ON c.oid = i.indrelid
            WHERE i.indisprimary
              AND c.relkind = 'r'
              AND c.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'public')
        ");

        foreach ($tables as $row) {
            $table = $row->tablename;
            $column = $row->attname;

            $seqName = "{$table}_{$column}_seq";
            $maxId = DB::table($table)->max($column);

            DB::statement("SELECT setval('\"{$seqName}\"', {$maxId}, true)");
        }
    }
}
