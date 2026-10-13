<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Attachment> */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return ['ticket_id' => Ticket::factory(), 'uploaded_by' => User::factory(), 'original_name' => 'example.txt', 'storage_key' => 'attachments/example.txt', 'disk' => 'private', 'mime_type' => 'text/plain', 'size_bytes' => 10, 'scan_status' => 'available'];
    }
}
