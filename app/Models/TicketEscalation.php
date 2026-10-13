<?php

namespace App\Models;

use Database\Factories\TicketEscalationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketEscalation extends Model
{
    /** @use HasFactory<TicketEscalationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['triggered_at' => 'immutable_datetime'];
    }
}
