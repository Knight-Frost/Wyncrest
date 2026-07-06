<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extends maintenance_requests with the fields a real ticketing workflow
     * needs: who filed it, vendor assignment + appointment, a waiting reason,
     * and labour/parts cost tracking (cents, per project convention).
     */
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->string('reported_by')->default('tenant')->after('landlord_id');

            $table->string('assignee_name')->nullable()->after('resolution_notes');
            $table->string('assignee_phone')->nullable()->after('assignee_name');
            $table->string('assignee_type')->nullable()->after('assignee_phone');
            $table->timestamp('assigned_at')->nullable()->after('acknowledged_at');
            $table->timestamp('appointment_at')->nullable()->after('assigned_at');
            $table->date('expected_completion_date')->nullable()->after('appointment_at');
            $table->text('waiting_reason')->nullable()->after('assignee_type');

            $table->unsignedInteger('labor_cost_cents')->nullable()->after('waiting_reason');
            $table->unsignedInteger('parts_cost_cents')->nullable()->after('labor_cost_cents');
            $table->string('invoice_reference')->nullable()->after('parts_cost_cents');
            $table->text('cost_notes')->nullable()->after('invoice_reference');
            $table->boolean('cost_paid')->default(false)->after('cost_notes');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropColumn([
                'reported_by',
                'assignee_name',
                'assignee_phone',
                'assignee_type',
                'assigned_at',
                'appointment_at',
                'expected_completion_date',
                'waiting_reason',
                'labor_cost_cents',
                'parts_cost_cents',
                'invoice_reference',
                'cost_notes',
                'cost_paid',
            ]);
        });
    }
};
