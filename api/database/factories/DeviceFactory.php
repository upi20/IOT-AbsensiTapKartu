<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'ESP32 '.fake()->word(),
            'location' => fake()->optional()->city(),
            'api_key' => Device::hashKey(Device::generatePlainKey()),
        ];
    }

    /** Pakai plain key tertentu (berguna di test). */
    public function withKey(string $plainKey): static
    {
        return $this->state(fn () => ['api_key' => Device::hashKey($plainKey)]);
    }
}
