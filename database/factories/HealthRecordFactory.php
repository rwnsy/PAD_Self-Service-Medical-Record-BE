<?php

namespace Database\Factories;

use App\Models\HealthRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthRecord>
 */
class HealthRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->for(Role::factory(), 'role'),
            'height' => 170,
            'weight' => 65,
            'systolic' => 120,
            'diastolic' => 80,
            'bmi' => 22.49,
            'warning_status' => 'ATTENTION',
            'warning_message' => 'Test record.',
            'recorded_at' => now(),
        ];
    }
}
