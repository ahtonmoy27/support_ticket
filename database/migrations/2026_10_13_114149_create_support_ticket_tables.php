<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_locks', function (Blueprint $t) {
            $t->string('name')->primary();
        });
        DB::table('support_locks')->insert([['name' => 'assignment'], ['name' => 'sla_policy']]);
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 20)->default('customer')->index();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_available')->default(true);
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('agent_categories', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('category_id')->constrained()->restrictOnDelete();
            $t->primary(['user_id', 'category_id']);
        });
        Schema::create('business_calendars', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('timezone');
            $t->json('hours');
            $t->json('exceptions')->nullable();
            $t->timestamps();
        });
        Schema::create('sla_policies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('business_calendar_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->boolean('is_active')->default(false)->index();
            $t->timestamps();
        });
        Schema::create('sla_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sla_policy_id')->constrained()->restrictOnDelete();
            $t->string('priority', 20);
            $t->unsignedInteger('resolution_minutes');
            $t->unsignedTinyInteger('warning_percent')->default(80);
            $t->unique(['sla_policy_id', 'priority']);
            $t->timestamps();
        });
        Schema::create('ticket_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('filename');
            $t->string('storage_key');
            $t->string('status', 20)->default('pending');
            foreach (['total_rows', 'processed_rows', 'failed_rows', 'skipped_rows'] as $column) {
                $t->unsignedInteger($column)->default(0);
            }
            $t->text('error')->nullable();
            $t->timestamps();
        });
        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->string('ticket_number')->nullable()->unique();
            $t->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_agent_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('category_id')->constrained()->restrictOnDelete();
            $t->string('subject');
            $t->text('description');
            $t->string('priority', 20);
            $t->string('status', 30)->default('open');
            $t->foreignId('sla_rule_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('sla_target_minutes');
            $t->unsignedTinyInteger('sla_warning_percent');
            $t->json('sla_calendar_snapshot');
            $t->timestamp('sla_started_at');
            $t->timestamp('sla_warning_at');
            $t->timestamp('sla_due_at');
            foreach (['sla_breached_at', 'resolved_at', 'closed_at'] as $column) {
                $t->timestamp($column)->nullable();
            }
            $t->string('source_system', 100)->nullable();
            $t->string('external_ticket_id', 100)->nullable();
            $t->unique(['source_system', 'external_ticket_id']);
            $t->timestamps();
            $t->index(['customer_id', 'created_at', 'id']);
            $t->index(['assigned_agent_id', 'status', 'sla_due_at']);
            $t->index(['status', 'sla_due_at', 'id']);
            $t->index(['status', 'sla_warning_at', 'id']);
            $t->index(['priority', 'status']);
            $t->index(['category_id', 'created_at']);
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $t->fullText(['subject', 'description']);
            }
        });
        Schema::create('ticket_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->text('body');
            $t->string('visibility', 10)->default('public');
            $t->timestamps();
            $t->index(['ticket_id', 'created_at', 'id']);
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $t->foreignId('ticket_message_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('original_name');
            $t->string('storage_key');
            $t->string('disk');
            $t->string('mime_type');
            $t->unsignedBigInteger('size_bytes');
            $t->string('scan_status', 20)->default('available');
            $t->timestamps();
        });
        Schema::create('ticket_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action');
            $t->string('visibility', 10)->default('public');
            $t->json('old_values');
            $t->json('new_values');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['ticket_id', 'created_at', 'id']);
        });
        Schema::create('ticket_escalations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('cycle')->default(1);
            $t->string('level', 10);
            $t->timestamp('triggered_at');
            $t->unique(['ticket_id', 'cycle', 'level']);
        });
        Schema::create('import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_import_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('row_number');
            $t->string('status', 20);
            $t->json('errors')->nullable();
            $t->foreignId('ticket_id')->nullable()->constrained()->restrictOnDelete();
            $t->unique(['ticket_import_id', 'row_number']);
            $t->timestamps();
        });
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $t->string('event_type');
            $t->json('recipient_ids');
            $t->timestamp('dispatched_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_locks');
        foreach (['notifications', 'outbox_events', 'import_rows', 'ticket_escalations', 'ticket_activities', 'attachments', 'ticket_messages', 'tickets', 'ticket_imports', 'sla_rules', 'sla_policies', 'business_calendars', 'agent_categories', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropIndex(['role']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'is_active', 'is_available']));
    }
};
