<?php

namespace Tests\Feature\Ledger;

use App\Enums\LedgerStatus;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LedgerImmutabilityTest (forensic audit WYN-006)
 *
 * LedgerEntry overrode update()/delete()/forceDelete(), but Eloquent's save()
 * on an existing model goes through performUpdate(), not update(), so
 * `$entry->amount_cents = ...; $entry->save()` silently rewrote a financial
 * record. Model events now refuse any Eloquent update or delete; the only
 * sanctioned status change, transitionStatus(), is a query-builder
 * compare-and-swap and is unaffected.
 */
class LedgerImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_cannot_rewrite_an_existing_entry(): void
    {
        $entry = LedgerEntry::factory()->rent()->pending()->create(['amount_cents' => 250000]);

        $entry->amount_cents = 1;

        try {
            $entry->save();
            $this->fail('save() must not rewrite a ledger entry.');
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame(250000, (int) LedgerEntry::whereKey($entry->id)->value('amount_cents'));
    }

    public function test_quiet_and_instance_deletes_are_refused(): void
    {
        $entry = LedgerEntry::factory()->rent()->pending()->create();

        try {
            $entry->deleteQuietly();
            $this->fail('deleteQuietly() must not remove a ledger entry.');
        } catch (\Exception) {
            // expected
        }

        $this->assertTrue(LedgerEntry::whereKey($entry->id)->exists());
    }

    public function test_sanctioned_status_transition_still_works(): void
    {
        $entry = LedgerEntry::factory()->rent()->pending()->create();

        $this->assertTrue($entry->transitionStatus(LedgerStatus::WAIVED));
        $this->assertSame(LedgerStatus::WAIVED, $entry->fresh()->status);
    }
}
