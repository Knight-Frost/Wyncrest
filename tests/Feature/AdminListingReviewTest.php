<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\NotificationType;
use App\Enums\PropertyType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\ListingNote;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the admin Listing Review command centre: queue, detail, tenant
 * preview, approve/reject decisions, internal notes, authorization gating,
 * and — critically — that every computed signal reflects real data.
 */
class AdminListingReviewTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::factory()->create(['is_super_admin' => true]);
    }

    /**
     * Build a pending listing with a real unit/property/landlord chain.
     */
    private function makePendingListing(array $overrides = [], array $landlordOverrides = []): Listing
    {
        $landlord = User::factory()->landlord()->create(array_merge([
            'identity_verified' => true,
            'account_status' => 'active',
            'is_active' => true,
        ], $landlordOverrides));

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);

        return Listing::factory()->pendingReview()->create(array_merge([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'description' => str_repeat('A comfortable, well maintained home. ', 5),
        ], $overrides));
    }

    /**
     * Seed N active listings in the same city + property type with the given
     * rents, so the pricing comparison has real comparables to work with.
     */
    private function seedComparableActiveListings(string $city, PropertyType $type, array $rents): void
    {
        foreach ($rents as $rent) {
            $property = Property::factory()->create(['city' => $city, 'property_type' => $type]);
            $unit = Unit::factory()->create(['property_id' => $property->id, 'rent_amount' => $rent]);
            Listing::factory()->active()->create(['unit_id' => $unit->id, 'landlord_id' => $property->landlord_id]);
        }
    }

    // ── Authorization ──────────────────────────────────────────────────────

    public function test_tenant_cannot_access_review_queue(): void
    {
        Sanctum::actingAs(User::factory()->tenant()->create(), [], 'sanctum');
        // A tenant bearer identity is unauthenticated on the admin session guard.
        $this->getJson('/api/admin/listings/review')->assertUnauthorized();
    }

    public function test_landlord_cannot_access_review_queue(): void
    {
        Sanctum::actingAs(User::factory()->landlord()->create(), [], 'sanctum');
        $this->getJson('/api/admin/listings/review')->assertUnauthorized();
    }

    public function test_scoped_admin_without_capability_is_forbidden(): void
    {
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
        $this->actingAs($scoped, 'admin');
        $this->getJson('/api/admin/listings/review')->assertForbidden();
    }

    public function test_scoped_admin_with_capability_can_access(): void
    {
        $scoped = Admin::factory()->create([
            'is_super_admin' => false,
            'capabilities' => ['moderate_listings'],
        ]);
        $this->actingAs($scoped, 'admin');
        $this->getJson('/api/admin/listings/review')->assertOk();
    }

    // ── Queue + counts ─────────────────────────────────────────────────────

    public function test_queue_returns_truthful_counts_and_data(): void
    {
        $this->makePendingListing();
        $this->makePendingListing();
        Listing::factory()->active()->create();
        Listing::factory()->rejected()->create(['rejection_reason' => 'Missing required photos and details.']);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson('/api/admin/listings/review')->assertOk();

        $res->assertJsonPath('counts.pending', 2)
            ->assertJsonPath('counts.approved', 1)
            ->assertJsonPath('counts.rejected', 1)
            ->assertJsonPath('counts.all', 4);

        // Default queue (pending) shows exactly the two pending listings.
        $this->assertCount(2, $res->json('data'));
    }

    public function test_queue_can_filter_by_status(): void
    {
        $this->makePendingListing();
        Listing::factory()->active()->create();

        $this->actingAs($this->admin, 'admin');

        $this->assertCount(1, $this->getJson('/api/admin/listings/review?status=approved')->json('data'));
        $this->assertCount(1, $this->getJson('/api/admin/listings/review?status=pending')->json('data'));
        $this->assertCount(2, $this->getJson('/api/admin/listings/review?status=all')->json('data'));
    }

    public function test_queue_search_matches_title(): void
    {
        $this->makePendingListing(['title' => 'Sunlit loft in Osu']);
        $this->makePendingListing(['title' => 'Garden bungalow']);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson('/api/admin/listings/review?search=loft')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Sunlit loft in Osu', $res->json('data.0.title'));
    }

    // ── Detail ─────────────────────────────────────────────────────────────

    public function test_detail_returns_real_listing_unit_property_landlord(): void
    {
        $listing = $this->makePendingListing(['title' => 'Skyline apartment']);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();

        $res->assertJsonPath('title', 'Skyline apartment')
            ->assertJsonPath('unit.unit_number', $listing->unit->unit_number)
            ->assertJsonPath('property.name', $listing->unit->property->name)
            ->assertJsonPath('landlord.name', $listing->landlord->full_name)
            ->assertJsonPath('reviewable', true);

        $res->assertJsonStructure([
            'checklist', 'warnings', 'completeness' => ['passed', 'total', 'percent'],
            'timeline', 'notes', 'verification', 'photos', 'photo_count',
        ]);
    }

    public function test_checklist_flags_missing_photos_as_failure(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();

        $photos = collect($res->json('checklist'))->firstWhere('key', 'photos');
        $this->assertSame('fail', $photos['status']);
        $this->assertSame(0, $res->json('photo_count'));

        // A hard failure means the listing is not ready for approval.
        $this->assertFalse($res->json('ready_for_approval'));
    }

    public function test_unverified_landlord_produces_warning_not_failure(): void
    {
        $listing = $this->makePendingListing([], ['identity_verified' => false]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();

        $verified = collect($res->json('checklist'))->firstWhere('key', 'landlord_verified');
        $this->assertSame('warn', $verified['status']);
    }

    public function test_duplicate_active_listing_is_detected(): void
    {
        $listing = $this->makePendingListing();
        // A second, already-active listing on the same unit.
        Listing::factory()->active()->create([
            'unit_id' => $listing->unit_id,
            'landlord_id' => $listing->landlord_id,
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();

        $this->assertTrue($res->json('verification.duplicate_active_listing'));
        $dupe = collect($res->json('checklist'))->firstWhere('key', 'duplicate');
        $this->assertSame('fail', $dupe['status']);
    }

    // ── Approve ────────────────────────────────────────────────────────────

    public function test_admin_can_approve_pending_listing(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/approve")
            ->assertOk()
            ->assertJsonPath('listing.status', ListingStatus::ACTIVE->value);

        $listing->refresh();
        $this->assertSame(ListingStatus::ACTIVE, $listing->status);
        $this->assertNotNull($listing->published_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'listing_published',
            'subject_id' => $listing->id,
        ]);
    }

    public function test_cannot_approve_non_pending_listing(): void
    {
        $listing = Listing::factory()->draft()->create();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/approve")
            ->assertStatus(422);
    }

    public function test_approve_with_internal_note_records_note(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/approve", [
            'internal_note' => 'Verified ownership documents out of band.',
        ])->assertOk();

        $this->assertDatabaseHas('listing_notes', [
            'listing_id' => $listing->id,
            'admin_id' => $this->admin->id,
            'body' => 'Verified ownership documents out of band.',
        ]);
    }

    // ── Reject ─────────────────────────────────────────────────────────────

    public function test_reject_requires_a_reason(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_reject_rejects_too_short_reason(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/reject", [
            'reason' => 'too short',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_admin_can_reject_with_reason_and_internal_note(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/reject", [
            'reason' => 'Please upload at least three clear photos of the unit interior.',
            'internal_note' => 'Second offence for this landlord.',
        ])->assertOk()->assertJsonPath('listing.status', ListingStatus::REJECTED->value);

        $listing->refresh();
        $this->assertSame(ListingStatus::REJECTED, $listing->status);
        $this->assertSame($this->admin->id, $listing->reviewed_by);
        $this->assertNotNull($listing->rejection_reason);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'listing_rejected',
            'subject_id' => $listing->id,
        ]);
        $this->assertDatabaseHas('listing_notes', [
            'listing_id' => $listing->id,
            'body' => 'Second offence for this landlord.',
        ]);
    }

    // ── Notes ──────────────────────────────────────────────────────────────

    public function test_admin_can_add_internal_note(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/notes", [
            'body' => 'Called landlord to confirm availability date.',
        ])->assertCreated()->assertJsonPath('note.admin_name', $this->admin->name);

        $this->assertDatabaseCount('listing_notes', 1);
    }

    public function test_note_body_is_required(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/notes", ['body' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_notes_appear_in_detail(): void
    {
        $listing = $this->makePendingListing();
        ListingNote::factory()->create([
            'listing_id' => $listing->id,
            'admin_id' => $this->admin->id,
            'body' => 'Reviewed the neighbourhood comps.',
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();

        $this->assertCount(1, $res->json('notes'));
        $this->assertSame('Reviewed the neighbourhood comps.', $res->json('notes.0.body'));
    }

    // ── Preview ────────────────────────────────────────────────────────────

    public function test_preview_returns_tenant_safe_payload(): void
    {
        $listing = $this->makePendingListing(['title' => 'Preview me']);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}/preview")->assertOk();

        $res->assertJsonPath('title', 'Preview me')
            ->assertJsonStructure(['photos', 'unit', 'property', 'landlord' => ['name', 'identity_verified']]);

        // Admin-only fields must NOT leak into the tenant preview.
        $res->assertJsonMissingPath('checklist')
            ->assertJsonMissingPath('warnings')
            ->assertJsonMissingPath('notes')
            ->assertJsonMissingPath('landlord.email');
    }

    // ── Timeline ───────────────────────────────────────────────────────────

    public function test_timeline_reflects_real_audit_events(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/reject", [
            'reason' => 'Please add a full description and interior photos before resubmitting.',
        ])->assertOk();

        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();
        $keys = collect($res->json('timeline'))->pluck('key');

        $this->assertTrue($keys->contains('created'));
        $this->assertTrue($keys->contains('listing_rejected'));
        $this->assertGreaterThan(0, AuditLog::where('action', 'listing_rejected')->count());
    }

    // ── Content checks: PII + exclusionary language ─────────────────────────

    public function test_contact_details_in_description_are_flagged(): void
    {
        $listing = $this->makePendingListing([
            'description' => 'Lovely two-bed apartment. Call me directly on 024 555 0130 or email me at owner@example.com to book fast.',
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();

        $item = collect($res->json('checklist'))->firstWhere('key', 'no_contact_info');
        $this->assertSame('warn', $item['status']);

        // The matched spans are surfaced for highlighting.
        $this->assertContains('owner@example.com', $res->json('content_flags.pii'));
        $this->assertContains('024 555 0130', $res->json('content_flags.pii'));
    }

    public function test_clean_description_passes_content_checks(): void
    {
        $listing = $this->makePendingListing([
            'description' => 'A bright, well maintained two-bedroom apartment with fitted kitchen, backup water and secure parking.',
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();

        $pii = collect($res->json('checklist'))->firstWhere('key', 'no_contact_info');
        $policy = collect($res->json('checklist'))->firstWhere('key', 'no_exclusionary_language');
        $this->assertSame('pass', $pii['status']);
        $this->assertSame('pass', $policy['status']);
        $this->assertSame([], $res->json('content_flags.pii'));
        $this->assertSame([], $res->json('content_flags.policy_phrases'));
    }

    public function test_exclusionary_language_is_flagged_as_advisory_warning(): void
    {
        $listing = $this->makePendingListing([
            'description' => 'Spacious family house in a quiet area. Professionals only, no children please.',
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();

        $item = collect($res->json('checklist'))->firstWhere('key', 'no_exclusionary_language');
        // Advisory only — a warning, never a hard failure. It appears as a
        // "medium" warning, not in the blocking (fail) set.
        $this->assertSame('warn', $item['status']);
        $this->assertNotEmpty($res->json('content_flags.policy_phrases'));

        $policyWarning = collect($res->json('warnings'))->firstWhere('key', 'no_exclusionary_language');
        $this->assertSame('medium', $policyWarning['severity']);
    }

    // ── Pricing: guarded area median ────────────────────────────────────────

    public function test_pricing_reports_no_comparison_without_enough_comparables(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();

        // Real rent/deposit facts are always present; the median comparison is not.
        $this->assertFalse($res->json('pricing.has_comparison'));
        $this->assertNull($res->json('pricing.median'));
        $this->assertNotNull($res->json('pricing.rent'));
    }

    public function test_pricing_computes_median_and_flags_outlier(): void
    {
        $landlord = User::factory()->landlord()->create(['identity_verified' => true, 'is_active' => true]);
        $property = Property::factory()->create([
            'landlord_id' => $landlord->id,
            'city' => 'Cantonments',
            'property_type' => PropertyType::APARTMENT,
        ]);
        $unit = Unit::factory()->create(['property_id' => $property->id, 'rent_amount' => 12000]);
        $listing = Listing::factory()->pendingReview()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'description' => str_repeat('A comfortable, well maintained home. ', 5),
        ]);

        // Comparable active listings in the same city + type: median = 4000.
        $this->seedComparableActiveListings('Cantonments', PropertyType::APARTMENT, [3000, 4000, 5000]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/listings/review/{$listing->id}")->assertOk();

        $this->assertTrue($res->json('pricing.has_comparison'));
        $this->assertSame(3, $res->json('pricing.comparable_count'));
        $this->assertEquals(4000, $res->json('pricing.median'));
        $this->assertEquals(200, $res->json('pricing.percent_diff'));
        $this->assertTrue($res->json('pricing.is_outlier'));
    }

    // ── Request changes (send back to draft) ────────────────────────────────

    public function test_admin_can_request_changes_sending_listing_back_to_draft(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/request-changes", [
            'reason' => 'Please add at least three interior photos and a floor area before resubmitting.',
        ])->assertOk()->assertJsonPath('listing.status', ListingStatus::DRAFT->value);

        $listing->refresh();
        $this->assertSame(ListingStatus::DRAFT, $listing->status);
        $this->assertNotNull($listing->changes_requested_at);
        $this->assertNotNull($listing->changes_requested_reason);
        // Sending back is NOT a rejection: it must not stigmatise the listing.
        $this->assertNull($listing->rejection_reason);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'listing_changes_requested',
            'subject_id' => $listing->id,
        ]);
    }

    public function test_request_changes_requires_a_reason(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/request-changes", ['reason' => 'too short'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_request_changes_notifies_the_landlord(): void
    {
        $listing = $this->makePendingListing();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/request-changes", [
            'reason' => 'Please upload clearer photos of the kitchen and living room before resubmitting.',
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $listing->landlord_id,
            'type' => NotificationType::LISTING_CHANGES_REQUESTED->value,
        ]);
    }

    public function test_cannot_request_changes_on_non_pending_listing(): void
    {
        $listing = Listing::factory()->active()->create();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/request-changes", [
            'reason' => 'This should not be allowed because the listing is already live.',
        ])->assertStatus(422);
    }

    public function test_resubmitting_a_draft_clears_the_change_request(): void
    {
        // Landlord must be fully verified to (re)submit a listing for review.
        $listing = $this->makePendingListing([], ['verification_status' => 'verified']);

        // Admin sends it back…
        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/listings/review/{$listing->id}/request-changes", [
            'reason' => 'Please add a full description and interior photos before resubmitting.',
        ])->assertOk();

        // …landlord fixes and resubmits.
        Sanctum::actingAs($listing->landlord, [], 'sanctum');
        $this->postJson("/api/landlord/listings/{$listing->id}/submit")->assertOk();

        $listing->refresh();
        $this->assertSame(ListingStatus::PENDING_REVIEW, $listing->status);
        $this->assertNull($listing->changes_requested_reason);
        $this->assertNull($listing->changes_requested_at);
    }
}
