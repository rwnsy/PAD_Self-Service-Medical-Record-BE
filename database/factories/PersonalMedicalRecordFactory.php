<?php

namespace Database\Factories;

use App\Models\PersonalMedicalRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonalMedicalRecord>
 */
class PersonalMedicalRecordFactory extends Factory
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
            'visit_date' => fake()->date('Y-m-d', 'now'),
            'facility_name' => 'Klinik Pratama Sehat',
            'complaint' => fake()->sentence(),
            'medications' => fake()->randomElement(['Paracetamol 500mg', 'Amoxicillin 500mg', null]),
            'source' => 'USER_REPORTED',
        ];
    }
}

