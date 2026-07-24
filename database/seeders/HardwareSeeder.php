<?php

namespace Database\Seeders;

use App\Models\Hardware;
use Illuminate\Database\Seeder;

class HardwareSeeder extends Seeder
{
    public function run(): void
    {
        $csvFile = __DIR__ . '/data/hardware_data.csv';
        $handle = fopen($csvFile, 'r');

        if ($handle === false) {
            $this->command->error('Cannot open hardware_data.csv');
            return;
        }

        // Skip header (tab-delimited)
        fgetcsv($handle, 0, "\t");

        $rows = [];
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        $this->command->info("Seeding " . count($rows) . " hardware records...");

        foreach ($rows as $row) {
            Hardware::create([
                'n_code' => $row[0],
                'pc_name' => $row[1],
                'type' => $this->clean($row[2]),
                'os' => $this->clean($row[3]),
                'ip_valid' => $this->clean($row[4]),
                'ip_local' => $this->clean($row[5]),
                'mac' => $this->clean($row[6]),
                'net_type' => $this->clean($row[7]),
                'switch' => $this->clean($row[8]),
                'port' => $this->clean($row[9]),
                'shutdown' => $row[10] === '1',
                'vlan' => $this->clean($row[11]),
                'motherboard' => $this->clean($row[12]),
                'cpu' => $this->clean($row[13]),
                'ram' => $this->clean($row[14]),
                'hdd' => $this->clean($row[15]),
                'comments' => $this->clean($row[16]),
                'mark' => $row[17] === '1',
                'clean_at' => $this->cleanDate($row[18]),
            ]);
        }
    }

    private function clean(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === '\\N' || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    private function cleanDate(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === '\\N' || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        return null;
    }
}
