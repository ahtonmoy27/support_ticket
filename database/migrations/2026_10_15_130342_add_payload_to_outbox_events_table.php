<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('recipient_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
