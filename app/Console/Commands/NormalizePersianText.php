<?php

namespace App\Console\Commands;

use App\Models\Hardware;
use App\Models\Person;
use App\Traits\PersianNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('normalize:persian-text')]
#[Description('Normalize Arabic ي/ك to Persian ی/ک in all text fields across the database')]
class NormalizePersianText extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Normalizing Persian text in hardwares table...');
        $count = 0;

        Hardware::chunk(100, function ($hardwares) use (&$count) {
            foreach ($hardwares as $hardware) {
                $dirty = false;
                $fields = ['pc_name', 'type', 'os', 'cpu', 'ram', 'hdd', 'net_type', 'switch', 'vlan', 'motherboard', 'comments'];

                foreach ($fields as $field) {
                    if (! empty($hardware->$field) && is_string($hardware->$field)) {
                        $normalized = PersianNormalizer::normalize($hardware->$field);
                        if ($normalized !== $hardware->$field) {
                            $hardware->$field = $normalized;
                            $dirty = true;
                        }
                    }
                }

                if ($dirty) {
                    $hardware->saveQuietly();
                    $count++;
                }
            }
        });

        $this->info("Normalized {$count} hardware records.");

        $this->info('Normalizing Persian text in persons table...');
        $personCount = 0;

        Person::chunk(100, function ($persons) use (&$personCount) {
            foreach ($persons as $person) {
                $dirty = false;
                $fields = ['f_name', 'l_name'];

                foreach ($fields as $field) {
                    if (! empty($person->$field) && is_string($person->$field)) {
                        $normalized = PersianNormalizer::normalize($person->$field);
                        if ($normalized !== $person->$field) {
                            $person->$field = $normalized;
                            $dirty = true;
                        }
                    }
                }

                if ($dirty) {
                    $person->saveQuietly();
                    $personCount++;
                }
            }
        });

        $this->info("Normalized {$personCount} person records.");
        $this->newLine();
        $this->info('Done!');
    }
}
