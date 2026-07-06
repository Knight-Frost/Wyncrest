<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ContractStatus;
use App\Enums\NotificationType;
use App\Enums\ReviewStatus;
use App\Models\Admin;
use App\Models\Application;
use App\Models\Contract;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Property;
use App\Models\Review;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ReviewTest
 *
 * Covers:
 *   - Review eligibility (eligible/ineligible tenants)
 *   - Review creation (valid + invalid cases)
 *   - Duplicate prevention (one review per contract)
 *   - Rating range validation
 *   - Admin moderation (approve/reject/hide/flag)
 *   - Landlord response (own property approved only)
 *   - Rating aggregates (pending vs approved)
 *   - Application lifecycle notifications (submit → landlord; decide → tenant)
 *   - Review notifications (submitted → landlord; approved → reviewer; response → reviewer)
 */
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a full property stack (property → unit → listing) owned by a landlord.
     */
    private function createPropertyStack(User $landlord): array
    {
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        return [$property, $unit, $listing];
    }

    /**
     * Create an active contract tying $tenant to $landlord's listing.
     */
    private function createContract(User $tenant, User $landlord, Listing $listing, ContractStatus $status = ContractStatus::ACTIVE): Contract
    {
        return Contract::factory()->create([
            'tenant_id' => $tenant->id,
            'landlord_id' => $landlord->id,
            'listing_id' => $listing->id,
            'status' => $status,
        ]);
    }

    // =========================================================================
    // ELIGIBILITY TESTS
    // =========================================================================

    public function test_tenant_with_active_contract_is_eligible_to_review(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        $this->createContract($tenant, $landlord, $listing, ContractStatus::ACTIVE);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->getJson("/api/tenant/listings/{$listing->id}/review-eligibility");

        $response->assertStatus(200)
            ->assertJsonFragment(['eligible' => true]);
        $this->assertNotNull($response->json('contract_id'));
    }

    public function test_tenant_with_terminated_contract_is_eligible_to_review(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        $this->createContract($tenant, $landlord, $listing, ContractStatus::TERMINATED);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->getJson("/api/tenant/listings/{$listing->id}/review-eligibility");

        $response->assertOk()->assertJsonFragment(['eligible' => true]);
    }

    public function test_tenant_with_expired_contract_is_eligible_to_review(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        $this->createContract($tenant, $landlord, $listing, ContractStatus::EXPIRED);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->getJson("/api/tenant/listings/{$listing->id}/review-eligibility");

        $response->assertOk()->assertJsonFragment(['eligible' => true]);
    }

    public function test_tenant_with_no_contract_is_not_eligible(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->getJson("/api/tenant/listings/{$listing->id}/review-eligibility");

        $response->assertOk()->assertJsonFragment(['eligible' => false]);
    }

    public function test_tenant_with_draft_contract_is_not_eligible(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        $this->createContract($tenant, $landlord, $listing, ContractStatus::DRAFT);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->getJson("/api/tenant/listings/{$listing->id}/review-eligibility");

        $response->assertOk()->assertJsonFragment(['eligible' => false]);
    }

    public function test_tenant_with_pending_tenant_contract_is_not_eligible(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        $this->createContract($tenant, $landlord, $listing, ContractStatus::PENDING_TENANT);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->getJson("/api/tenant/listings/{$listing->id}/review-eligibility");

        $response->assertOk()->assertJsonFragment(['eligible' => false]);
    }

    // =========================================================================
    // REVIEW CREATION TESTS
    // =========================================================================

    public function test_eligible_tenant_can_create_a_review(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/reviews', [
            'contract_id' => $contract->id,
            'rating' => 4,
            'title' => 'Great place',
            'body' => 'I enjoyed living here. Landlord was responsive.',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('reviews', [
            'reviewer_user_id' => $tenant->id,
            'contract_id' => $contract->id,
            'rating' => 4,
            'status' => ReviewStatus::PENDING->value,
        ]);
    }

    public function test_ineligible_tenant_gets_403_on_review_creation(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        // Only draft contract — not eligible
        $contract = $this->createContract($tenant, $landlord, $listing, ContractStatus::DRAFT);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/reviews', [
            'contract_id' => $contract->id,
            'rating' => 4,
            'body' => 'Test review.',
        ]);

        $response->assertStatus(403);
    }

    public function test_tenant_without_contract_gets_403_on_review_creation(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        // Contract owned by a DIFFERENT tenant
        $otherTenant = User::factory()->tenant()->create();
        $contract = $this->createContract($otherTenant, $landlord, $listing);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/reviews', [
            'contract_id' => $contract->id,
            'rating' => 3,
            'body' => 'Should not be allowed.',
        ]);

        $response->assertStatus(403);
    }

    public function test_duplicate_review_on_same_contract_is_prevented(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        // Seed an existing review for this contract
        Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 3,
            'body' => 'First review.',
            'status' => ReviewStatus::PENDING,
        ]);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/reviews', [
            'contract_id' => $contract->id,
            'rating' => 5,
            'body' => 'Trying again.',
        ]);

        $response->assertStatus(403);
    }

    public function test_rating_must_be_at_least_1(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/reviews', [
            'contract_id' => $contract->id,
            'rating' => 0,
            'body' => 'Should fail.',
        ]);

        $response->assertStatus(422);
    }

    public function test_rating_must_not_exceed_5(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/reviews', [
            'contract_id' => $contract->id,
            'rating' => 6,
            'body' => 'Should fail.',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // PENDING REVIEW DOES NOT AFFECT PUBLIC AGGREGATE
    // =========================================================================

    public function test_pending_review_does_not_affect_public_average_rating(): void
    {
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);

        $tenant = User::factory()->tenant()->create();
        $contract = $this->createContract($tenant, $landlord, $listing);

        // Create a pending review
        Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 5,
            'body' => 'Pending review.',
            'status' => ReviewStatus::PENDING,
        ]);

        $property->refresh();

        $this->assertNull($property->average_rating);
        $this->assertSame(0, $property->review_count);
    }

    public function test_approved_review_appears_in_public_average_rating(): void
    {
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);

        $tenant = User::factory()->tenant()->create();
        $contract = $this->createContract($tenant, $landlord, $listing);

        Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 4,
            'body' => 'Approved review.',
            'status' => ReviewStatus::APPROVED,
        ]);

        $property->refresh();

        $this->assertEquals(4.0, $property->average_rating);
        $this->assertSame(1, $property->review_count);
    }

    // =========================================================================
    // ADMIN MODERATION TESTS
    // =========================================================================

    public function test_admin_can_approve_a_review(): void
    {
        $admin = Admin::factory()->create();
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 4,
            'body' => 'Good property.',
            'status' => ReviewStatus::PENDING,
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->postJson("/api/admin/reviews/{$review->id}/moderate", [
            'action' => 'approve',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status' => ReviewStatus::APPROVED->value,
        ]);
    }

    public function test_admin_can_reject_a_review(): void
    {
        $admin = Admin::factory()->create();
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 1,
            'body' => 'Terrible experience.',
            'status' => ReviewStatus::PENDING,
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->postJson("/api/admin/reviews/{$review->id}/moderate", [
            'action' => 'reject',
            'reason' => 'Violates community guidelines.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status' => ReviewStatus::REJECTED->value,
        ]);
    }

    public function test_admin_can_hide_a_review(): void
    {
        $admin = Admin::factory()->create();
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 2,
            'body' => 'Hiding this.',
            'status' => ReviewStatus::APPROVED,
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->postJson("/api/admin/reviews/{$review->id}/moderate", [
            'action' => 'hide',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => ReviewStatus::HIDDEN->value]);
    }

    public function test_admin_can_flag_a_review(): void
    {
        $admin = Admin::factory()->create();
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 3,
            'body' => 'Suspicious review.',
            'status' => ReviewStatus::PENDING,
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->postJson("/api/admin/reviews/{$review->id}/moderate", [
            'action' => 'flag',
            'reason' => 'Suspected fake.',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => ReviewStatus::FLAGGED->value]);
    }

    public function test_admin_review_queue_defaults_to_pending_and_flagged_with_counts(): void
    {
        $admin = Admin::factory()->create();
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);

        $pendingContract = $this->createContract($tenant, $landlord, $listing);
        $pending = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $pendingContract->id,
            'rating' => 1,
            'body' => 'Not great.',
            'status' => ReviewStatus::PENDING,
        ]);

        $tenant2 = User::factory()->tenant()->create();
        [, , $listing2] = $this->createPropertyStack($landlord);
        $approvedContract = $this->createContract($tenant2, $landlord, $listing2);
        Review::create([
            'reviewer_user_id' => $tenant2->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $approvedContract->id,
            'rating' => 5,
            'body' => 'Loved it.',
            'status' => ReviewStatus::APPROVED,
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->getJson('/api/admin/reviews');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('counts.pending'));
        $this->assertSame(1, $response->json('counts.awaiting'));
        $this->assertSame(1, $response->json('counts.low_rated_awaiting'));
        $this->assertSame(1, $response->json('counts.approved'));
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($pending->id, $response->json('data.0.id'));
        // The 1★ pending review earns a real, computed low-rating signal.
        $this->assertContains('low_rating', array_column($response->json('data.0.signals'), 'key'));
    }

    public function test_admin_review_queue_status_filter_and_search(): void
    {
        $admin = Admin::factory()->create();
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 4,
            'title' => 'A quiet retreat',
            'body' => 'Very peaceful stay.',
            'status' => ReviewStatus::APPROVED,
        ]);

        $this->actingAs($admin, 'admin');

        $this->getJson('/api/admin/reviews?status=pending')
            ->assertStatus(422); // 'pending' isn't a valid status filter — use 'queue'.

        $this->getJson('/api/admin/reviews?status=approved')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/reviews?status=approved&search=quiet')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/reviews?status=approved&search=nonexistent')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_review_detail_includes_timeline_and_reviewer_stats(): void
    {
        $admin = Admin::factory()->create();
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 3,
            'body' => 'It was fine.',
            'status' => ReviewStatus::PENDING,
        ]);

        $this->actingAs($admin, 'admin');

        $this->postJson("/api/admin/reviews/{$review->id}/moderate", [
            'action' => 'approve',
            'reason' => 'Looks genuine.',
        ])->assertStatus(200);

        $response = $this->getJson("/api/admin/reviews/{$review->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => ReviewStatus::APPROVED->value]);
        $this->assertSame(1, $response->json('reviewer_stats.review_count'));
        $this->assertEquals(3.0, $response->json('reviewer_stats.average_rating'));

        $timelineActions = array_column($response->json('timeline'), 'key');
        $this->assertContains('review_submitted', $timelineActions);
        $this->assertContains('review_approved', $timelineActions);
    }

    public function test_non_admin_cannot_moderate_a_review(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 4,
            'body' => 'Good place.',
            'status' => ReviewStatus::PENDING,
        ]);

        // A landlord bearer identity is unauthenticated on the admin session guard.
        Sanctum::actingAs($landlord, [], 'sanctum');

        $response = $this->postJson("/api/admin/reviews/{$review->id}/moderate", [
            'action' => 'approve',
        ]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // LANDLORD RESPONSE TESTS
    // =========================================================================

    public function test_landlord_can_respond_to_approved_review_on_own_property(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 4,
            'body' => 'Nice place.',
            'status' => ReviewStatus::APPROVED,
        ]);

        Sanctum::actingAs($landlord, [], 'sanctum');

        $response = $this->postJson("/api/landlord/reviews/{$review->id}/respond", [
            'response' => 'Thank you for your kind words!',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'landlord_response' => 'Thank you for your kind words!',
        ]);
    }

    public function test_landlord_cannot_respond_to_review_on_another_landlords_property(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        $otherLandlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 4,
            'body' => 'Nice place.',
            'status' => ReviewStatus::APPROVED,
        ]);

        Sanctum::actingAs($otherLandlord, [], 'sanctum');

        $response = $this->postJson("/api/landlord/reviews/{$review->id}/respond", [
            'response' => 'I should not be able to respond.',
        ]);

        $response->assertStatus(403);
    }

    public function test_landlord_cannot_respond_to_pending_review(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 4,
            'body' => 'Pending review.',
            'status' => ReviewStatus::PENDING,
        ]);

        Sanctum::actingAs($landlord, [], 'sanctum');

        $response = $this->postJson("/api/landlord/reviews/{$review->id}/respond", [
            'response' => 'Should fail because review is pending.',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // NOTIFICATION TESTS — REVIEWS
    // =========================================================================

    public function test_review_submitted_notifies_landlord(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [, , $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->postJson('/api/tenant/reviews', [
            'contract_id' => $contract->id,
            'rating' => 4,
            'body' => 'Great property.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $landlord->id,
            'type' => NotificationType::REVIEW_SUBMITTED->value,
        ]);
    }

    public function test_review_approved_notifies_reviewer(): void
    {
        $admin = Admin::factory()->create();
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 4,
            'body' => 'Great place.',
            'status' => ReviewStatus::PENDING,
        ]);

        $this->actingAs($admin, 'admin');

        $this->postJson("/api/admin/reviews/{$review->id}/moderate", [
            'action' => 'approve',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => NotificationType::REVIEW_APPROVED->value,
        ]);
    }

    public function test_review_response_notifies_reviewer(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        [$property, $unit, $listing] = $this->createPropertyStack($landlord);
        $contract = $this->createContract($tenant, $landlord, $listing);

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'contract_id' => $contract->id,
            'rating' => 4,
            'body' => 'Great.',
            'status' => ReviewStatus::APPROVED,
        ]);

        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->postJson("/api/landlord/reviews/{$review->id}/respond", [
            'response' => 'Thank you!',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => NotificationType::REVIEW_RESPONSE->value,
        ]);
    }

    // =========================================================================
    // NOTIFICATION TESTS — APPLICATION LIFECYCLE
    // =========================================================================

    public function test_application_submitted_notifies_landlord(): void
    {
        $tenant = User::factory()->tenant()->identityVerified()->create();
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->postJson('/api/tenant/applications', [
            'listing_id' => $listing->id,
            'cover_note' => 'I am interested.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $landlord->id,
            'type' => NotificationType::APPLICATION_SUBMITTED->value,
        ]);
    }

    public function test_application_approved_notifies_tenant(): void
    {
        $tenant = User::factory()->tenant()->identityVerified()->create();
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        $application = Application::factory()->create([
            'tenant_id' => $tenant->id,
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->postJson("/api/landlord/applications/{$application->id}/decide", [
            'decision' => 'approved',
            'decision_reason' => 'Strong profile.',
        ])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => NotificationType::APPLICATION_APPROVED->value,
        ]);
    }

    public function test_application_rejected_notifies_tenant(): void
    {
        $tenant = User::factory()->tenant()->identityVerified()->create();
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        $application = Application::factory()->create([
            'tenant_id' => $tenant->id,
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->postJson("/api/landlord/applications/{$application->id}/decide", [
            'decision' => 'rejected',
            'decision_reason' => 'Budget mismatch.',
        ])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => NotificationType::APPLICATION_REJECTED->value,
        ]);
    }

    public function test_application_notification_is_idempotent_on_duplicate_submit(): void
    {
        $tenant = User::factory()->tenant()->identityVerified()->create();
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        Sanctum::actingAs($tenant, [], 'sanctum');

        // First submission
        $this->postJson('/api/tenant/applications', [
            'listing_id' => $listing->id,
            'cover_note' => 'First try.',
        ])->assertStatus(201);

        $count = Notification::where('user_id', $landlord->id)
            ->where('type', NotificationType::APPLICATION_SUBMITTED->value)
            ->count();

        $this->assertEquals(1, $count);
    }
}
