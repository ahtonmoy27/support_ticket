<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\ImportRow> */
class ImportRowFactory extends Factory {
    protected $model = \App\Models\ImportRow::class;
    public function definition(): array { return ['ticket_import_id'=>\App\Models\TicketImport::factory(),'row_number'=>1,'status'=>'failed','errors'=>['row'=>['Invalid row.']]]; }
}