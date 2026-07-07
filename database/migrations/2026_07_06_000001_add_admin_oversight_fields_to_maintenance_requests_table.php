<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            // Which platform admin owns/is triaging this case. Distinct from
            // assignee_name/assignee_type, which is the landlord's own
            // contractor/vendor — this is internal admin case ownership.
            $table->foreignId('handling_admin_id')->nullable()->after('landlord_id')
                ->constrained('admins')->nullOnDelete();

            // Admin-only escalation flag, separate from the tenant/landlord
            // visible MaintenanceStatus enum — escalating never changes the
            // status a tenant or landlord sees.
            $table->timestamp('escalated_at')->nullable()->after('closed_at');
            $table->text('escalation_reason')->nullable()->after('escalated_at');

            $table->index('handling_admin_id');
            $table->index('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handling_admin_id');
            $table->dropColumn(['escalated_at', 'escalation_reason']);
        });
    }
};
