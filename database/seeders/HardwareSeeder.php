<?php

namespace Database\Seeders;

use App\Models\Hardware;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HardwareSeeder extends Seeder
{
    /**
     * Seed hardware from the pre-processed device data.
     *
     * The CSV was parsed once and the cleaned result (null-normalized strings,
     * boolean shutdown/mark, ISO dates) is hardcoded in data/hardwares_data.php,
     * so this seeder no longer touches the raw CSV at runtime.
     */
    public function run(): void
    {
        $records = require __DIR__.'/data/hardwares_data.php';

        $existing = DB::table('hardwares')->pluck('pc_name')->all();
        $existing = array_flip($existing);

        $this->command->info('Seeding '.count($records).' hardware records...');

        foreach ($records as $row) {
            if (isset($existing[$row['pc_name']])) {
                continue;
            }
            Hardware::create($row);
        }
    }
}
