<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\Attachment> */
class AttachmentFactory extends Factory {
    protected $model = \App\Models\Attachment::class;
    public function definition(): array { return ['ticket_id'=>\App\Models\Ticket::factory(),'uploaded_by'=>\App\Models\User::factory(),'original_name'=>'example.txt','storage_key'=>'attachments/example.txt','disk'=>'private','mime_type'=>'text/plain','size_bytes'=>10,'scan_status'=>'available']; }
}