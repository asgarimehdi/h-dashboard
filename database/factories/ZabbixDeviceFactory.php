<?php

namespace Database\Factories;

use App\Models\ZabbixDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZabbixDevice>
 */
class ZabbixDeviceFactory extends Factory
{
    protected $model = ZabbixDevice::class;

    public function definition(): array
    {
        return [
            'name' => 'دستگاه '.fake()->unique()->numberBetween(1, 999999),
            'type' => ZabbixDevice::TYPE_NETWORK,
            'out_item_id' => (string) fake()->numberBetween(10000, 99999),
            'in_item_id' => (string) fake()->numberBetween(10000, 99999),
            'initial_duration' => 7200,
            'min' => null,
            'max' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function wireless(): static
    {
        return $this->state(fn () => [
            'type' => ZabbixDevice::TYPE_WIRELESS,
            'out_item_id' => null,
            'in_item_id' => null,
            'signal_item_id' => (string) fake()->numberBetween(10000, 99999),
            'frequency_item_id' => (string) fake()->numberBetween(10000, 99999),
            'response_item_id' => (string) fake()->numberBetween(10000, 99999),
            'min' => -85,
            'max' => -45,
        ]);
    }
}
