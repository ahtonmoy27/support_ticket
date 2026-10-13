<?php

namespace Database\Factories;

use App\Models\ImportRow;
use App\Models\TicketImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportRow> */
class ImportRowFactory extends Factory
{
    protected $model = ImportRow::class;

    public function definition(): array
    {
        return ['ticket_import_id' => TicketImport::factory(), 'row_number' => 1, 'status' => 'failed', 'errors' => ['row' => ['Invalid row.']]];
    }
}
