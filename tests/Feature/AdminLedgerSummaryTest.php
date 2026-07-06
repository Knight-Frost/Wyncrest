<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AdminLedgerSummaryTest
 *
 * HTTP-level regression coverage for the reported bug: the admin Ledger
 * page showed "Total Collected: GH₵ -28,100.00" and payments rendered as
 * negative amounts. Root cause: the frontend summed raw signed
 * `amount_cents` across mixed entry types over only the current paginated
 * page. This asserts the real endpoint response — summary is computed over
 * the full filtered set, not the page, and every entry carries
 * display-safe fields the frontend can render without guessing signs.
 */
class AdminLedgerSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::factory()->create();
    }

    /**
     * Reproduces the reported scenario: many settled months (rent + a
     * matching negative payment each) spread across more entries than fit
     * on a single page, so a page-scoped client sum would go negative or
     * nonsensical, but the server-computed summary must not.
     */
    public function test_summary_is_correct_across_many_entries_spanning_multiple_pages(): void
    {
        $contract = Contract::factory()->active()->create();

        // 40 settled months => 80 entries (rent + payment each), well past
        // the 50-per-page boundary, so a page-scoped sum would only see a
        // lopsided slice of rent vs. payment rows.
        for ($i = 0; $i < 40; $i++) {
            $rent = LedgerEntry::factory()->create([
                'contract_id' => $contract->id,
                'tenant_id' => $contract->tenant_id,
                'landlord_id' => $contract->landlord_id,
                'type' => LedgerType::RENT,
                'amount_cents' => 70_000,
                'status' => LedgerStatus::PAID,
                'due_date' => now()->subMonths($i),
            ]);
            LedgerEntry::factory()->create([
                'contract_id' => $contract->id,
                'tenant_id' => $contract->tenant_id,
                'landlord_id' => $contract->landlord_id,
                'type' => LedgerType::PAYMENT,
                'amount_cents' => -70_000,
                'status' => LedgerStatus::PAID,
                'related_rent_entry_id' => $rent->id,
                'due_date' => now()->subMonths($i),
            ]);
        }

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/ledger');
        $response->assertStatus(200);

        $summary = $response->json('summary');

        // The core regression: collected must be positive, not negative.
        $this->assertSame(2_800_000, $summary['collected_cents']);
        $this->assertGreaterThanOrEqual(0, $summary['collected_cents']);
        $this->assertSame(0, $summary['outstanding_cents']);
        $this->assertSame(0, $summary['overdue_cents']);
        $this->assertSame(2_800_000, $summary['rent_charged_cents']);

        // Every payment row is display-safe: positive amount, clearly
        // labelled, with the raw signed value only under balance_impact.
        $paymentRows = collect($response->json('data'))->where('financial_category', 'payment');
        $this->assertTrue($paymentRows->isNotEmpty());
        foreach ($paymentRows as $row) {
            $this->assertGreaterThan(0, $row['display_amount_cents']);
            $this->assertLessThan(0, $row['balance_impact_cents']);
            $this->assertLessThan(0, $row['signed_amount_cents']);
            $this->assertSame('Payment received', $row['display_label']);
            $this->assertSame('payment', $row['direction']);
        }

        // The summary computed over the full filtered set must equal the
        // summary computed over a *different* page — proving it does not
        // depend on which page was requested (the exact class of bug that
        // produced the negative "Total Collected" figure).
        $page2 = $this->getJson('/api/admin/ledger?page=2')->json('summary');
        $this->assertSame($summary, $page2);
    }

    public function test_summary_matches_platform_wide_reconciliation(): void
    {
        $contract = Contract::factory()->active()->create();
        $rent = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => 250_000,
            'status' => LedgerStatus::OVERDUE,
            'due_date' => now()->subDays(10),
        ]);

        $this->actingAs($this->admin, 'admin');

        $ledgerSummary = $this->getJson('/api/admin/ledger')->json('summary');
        $reconciliation = $this->getJson('/api/admin/ledger/reconciliation')->json();

        $this->assertSame('pass', $reconciliation['status']);
        $this->assertSame($ledgerSummary['outstanding_cents'], $reconciliation['summary']['outstanding_cents']);
        $this->assertSame($ledgerSummary['overdue_cents'], $reconciliation['summary']['overdue_cents']);
        $this->assertSame(250_000, $ledgerSummary['overdue_cents']);
    }

    public function test_non_admin_cannot_reach_reconciliation_endpoint(): void
    {
        $this->getJson('/api/admin/ledger/reconciliation')->assertStatus(401);
    }
}
