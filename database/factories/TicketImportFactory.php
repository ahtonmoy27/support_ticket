<?php

namespace Database\Factories;

use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketImport> */
class TicketImportFactory extends Factory
{
    protected $model = TicketImport::class;

    public function definition(): array
    {
        return ['created_by' => User::factory()->state(['role' => 'admin']), 'filename' => 'tickets.csv', 'storage_key' => 'imports/tickets.csv', 'status' => 'pending'];
    }
}
