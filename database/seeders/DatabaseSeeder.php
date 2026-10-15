<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SupportSeeder::class);
        $this->call(SampleTicketSeeder::class);
    }
}
