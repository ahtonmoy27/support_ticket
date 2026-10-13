<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\TicketImport> */
class TicketImportFactory extends Factory {
    protected $model = \App\Models\TicketImport::class;
    public function definition(): array { return ['created_by'=>\App\Models\User::factory()->state(['role'=>'admin']),'filename'=>'tickets.csv','storage_key'=>'imports/tickets.csv','status'=>'pending']; }
}