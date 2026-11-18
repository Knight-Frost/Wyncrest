<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First-of-month rent policy + admin due-date controls (launch closeout).
 *
 *  1. contracts.billing_anchor — 'calendar' (new policy) or 'anniversary'
 *     (legacy). Existing non-draft leases that start on a day other than the
 *     1st were signed with anniversary periods; they KEEP them so the period
 *     identity (contract_id, billing_period_start) of every future charge
 *     continues the sequence already on the ledger (no overlap, gap or
 *     duplicate). Leases starting on the 1st have identical periods under
 *     both rules and drafts are unsigned, so both move to 'calendar'.
 *  2. ledger_entries.original_due_date — the due date as originally posted.
 *     ledger_entries.due_date stays the EFFECTIVE due date every consumer
 *     reads; an admin adjustment changes only due_date and appends a row to
 *     ledger_due_date_revisions. Backfilled from due_date.
 *  3. rent_due_schedule_changes — append-only timeline of "from billing month
 *     X, rent is due on day N" decisions for a lease.
 *  4. ledger_due_date_revisions — append-only history of one-off adjustments.
 *
 * Forward-only data effects are additive (new columns/tables + backfill); no
 * existing amount, period, status or due date is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('billing_anchor', 16)->default('calendar')->after('payment_day');
        });

        DB::table('contracts')
            ->select(['id', 'start_date', 'status'])
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                $legacy = [];
                foreach ($rows as $row) {
                    if ($row->status !== 'draft' && Carbon::parse($row->start_date)->day !== 1) {
                        $legacy[] = $row->id;
                    }
                }
                if ($legacy) {
                    DB::table('contracts')->whereIn('id', $legacy)->update(['billing_anchor' => 'anniversary']);
                }
            });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->date('original_due_date')->nullable()->after('due_date');
        });

        DB::table('ledger_entries')
            ->whereNull('original_due_date')
            ->update(['original_due_date' => DB::raw('due_date')]);

        Schema::create('rent_due_schedule_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('contract_id')->constrained('contracts')->restrictOnDelete();
            // 1-31; months shorter than the day use their last day.
            $table->unsignedTinyInteger('due_day');
            // Always the 1st of a calendar month: the first billing month the
            // rule applies to (periods whose start falls on/after this date).
            $table->date('effective_from');
            $table->boolean('is_default')->default(false);
            $table->text('reason');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contract_id', 'id']);
        });

        Schema::create('ledger_due_date_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('ledger_entry_id')->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignUuid('contract_id')->constrained('contracts')->restrictOnDelete();
            $table->date('previous_due_date');
            $table->date('new_due_date');
            $table->string('previous_status', 16);
            $table->string('new_status', 16);
            $table->text('reason');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ledger_entry_id', 'id']);
            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_due_date_revisions');
        Schema::dropIfExists('rent_due_schedule_changes');

        // why: dropping original_due_date loses nothing that due_date does not
        // already hold for unadjusted entries; for ADJUSTED entries the posted
        // date survives only in the revisions table, which is dropped above.
        // Take a backup before rolling back past this migration.
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn('original_due_date');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('billing_anchor');
        });
    }
};
