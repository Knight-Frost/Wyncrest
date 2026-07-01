<?php

use App\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a SHA-256 hash chain to audit_logs so the "on the record" tamper-evidence
 * shown in the admin UI is real, not decorative:
 *   - hash          : sha256(previous_hash | canonical(row))
 *   - previous_hash : the prior row's hash (GENESIS for the first row)
 *
 * Existing rows are backfilled in id order using the SAME serialization the model
 * uses at write time (AuditLog::canonicalFields / chainHashFor), so a fresh
 * verify() over old + new rows validates as one continuous chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // char(64) hex SHA-256; nullable only so the column can be added to a
            // populated table before the backfill below fills every row.
            $table->string('hash', 64)->nullable()->after('severity');
            $table->string('previous_hash', 64)->nullable()->after('hash');
            $table->index('hash');
        });

        // Backfill the chain over any pre-existing rows, oldest first.
        $previous = AuditLog::GENESIS_HASH;
        AuditLog::query()->orderBy('id')->each(function (AuditLog $log) use (&$previous) {
            // Normalize stored timestamp to whole seconds for a stable payload.
            $log->created_at = $log->created_at?->copy()->micro(0);
            $hash = AuditLog::chainHashFor($previous, $log->canonicalFields());

            // Write directly (no model events) — these are historical rows.
            DB::table('audit_logs')->where('id', $log->id)->update([
                'previous_hash' => $previous,
                'hash' => $hash,
            ]);

            $previous = $hash;
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['hash']);
            $table->dropColumn(['hash', 'previous_hash']);
        });
    }
};
