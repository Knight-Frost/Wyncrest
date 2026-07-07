<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\MaintenanceRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MaintenanceAdminNote>
 */
class MaintenanceAdminNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'maintenance_request_id' => MaintenanceRequest::factory(),
            'admin_id' => Admin::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
