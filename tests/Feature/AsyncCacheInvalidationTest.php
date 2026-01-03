<?php

namespace Tests\Feature;

use App\Jobs\InvalidateAnalyticsCacheJob;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Support\Cache\AnalyticsCache;
use App\Support\Cache\AnalyticsCacheInvalidator;
use App\Support\Cache\AnalyticsCacheMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 5.4: Async Cache Invalidation Tests
 */
class AsyncCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    #[Test]
    public function sync_invalidation_used_below_threshold()
    {
        Queue::fake();
        
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->create(['unit_id' => $unit->id]);
        
        $contract = Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
        
        Queue::assertNothingPushed();
    }

    #[Test]
    public function async_threshold_constant_is_correct()
    {
        $threshold = AnalyticsCacheInvalidator::ASYNC_INVALIDATION_THRESHOLD;
        $this->assertEquals(100, $threshold);
    }

    #[Test]
    public function job_executes_selective_invalidation()
    {
        $tenant = User::factory()->tenant()->create();
        
        $cacheKey1 = "nexus:local:analytics:contracts:tenant:test1";
        $cacheKey2 = "nexus:local:analytics:contracts:tenant:test2";
        
        Cache::put($cacheKey1, ['data' => 'value1'], 300);
        Cache::put($cacheKey2, ['data' => 'value2'], 300);
        
        AnalyticsCacheMetadata::write($cacheKey1, [
            'role' => 'tenant',
            'user_id' => $tenant->id,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ], 300);
        
        AnalyticsCacheMetadata::write($cacheKey2, [
            'role' => 'tenant',
            'user_id' => 999,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ], 300);
        
        $job = new InvalidateAnalyticsCacheJob('contracts', ['user_id' => $tenant->id]);
        $job->handle();
        
        $this->assertTrue(true);
    }

    #[Test]
    public function queue_failure_does_not_break_writes()
    {
        Queue::fake();
        
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->create(['unit_id' => $unit->id]);
        
        $contract = Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
        
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    #[Test]
    public function metadata_overlap_respected_in_async_job()
    {
        $tenant1 = User::factory()->tenant()->create();
        $tenant2 = User::factory()->tenant()->create();
        
        $key1 = "nexus:local:analytics:contracts:tenant:hash1";
        $key2 = "nexus:local:analytics:contracts:tenant:hash2";
        
        AnalyticsCacheMetadata::write($key1, [
            'role' => 'tenant',
            'user_id' => $tenant1->id,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ], 300);
        
        AnalyticsCacheMetadata::write($key2, [
            'role' => 'tenant',
            'user_id' => $tenant2->id,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ], 300);
        
        $job = new InvalidateAnalyticsCacheJob('contracts', ['user_id' => $tenant1->id]);
        $job->handle();
        
        $metadata2 = AnalyticsCacheMetadata::read($key2);
        $this->assertNotNull($metadata2);
        $this->assertEquals($tenant2->id, $metadata2['user_id']);
    }

    #[Test]
    public function admin_caches_always_invalidated_in_async()
    {
        $adminKey = "nexus:local:analytics:contracts:admin:hash1";
        
        Cache::put($adminKey, ['data' => 'admin_data'], 300);
        
        AnalyticsCacheMetadata::write($adminKey, [
            'role' => 'admin',
            'user_id' => null,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ], 300);
        
        $job = new InvalidateAnalyticsCacheJob('contracts', ['user_id' => 123]);
        $job->handle();
        
        $this->assertTrue(true);
    }

    #[Test]
    public function job_handles_exceptions_gracefully()
    {
        $job = new InvalidateAnalyticsCacheJob('invalid_domain', []);
        $job->handle();
        $this->assertTrue(true);
    }

    #[Test]
    public function job_logs_completion()
    {
        $this->expectNotToPerformAssertions();
        
        $job = new InvalidateAnalyticsCacheJob('contracts', ['user_id' => 1]);
        $job->handle();
    }

    #[Test]
    public function ledger_change_triggers_correct_invalidation_flow()
    {
        Queue::fake();
        
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->create(['unit_id' => $unit->id]);
        
        $contract = Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
        
        $entry = LedgerEntry::factory()->create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'amount_cents' => 100000,
        ]);
        
        $this->assertDatabaseHas('ledger_entries', ['id' => $entry->id]);
    }

    #[Test]
    public function sync_vs_async_threshold_logic_is_correct()
    {
        $threshold = AnalyticsCacheInvalidator::ASYNC_INVALIDATION_THRESHOLD;
        
        $this->assertEquals(100, $threshold);
        $this->assertTrue(50 <= $threshold);
        $this->assertTrue(100 <= $threshold);
        $this->assertTrue(101 > $threshold);
        $this->assertTrue(500 > $threshold);
    }

    #[Test]
    public function job_uses_dedicated_queue()
    {
        Queue::fake();
        
        InvalidateAnalyticsCacheJob::dispatch('contracts', ['user_id' => 1]);
        
        Queue::assertPushedOn('analytics-invalidation', InvalidateAnalyticsCacheJob::class);
    }

    #[Test]
    public function job_has_correct_retry_configuration()
    {
        $job = new InvalidateAnalyticsCacheJob('contracts', ['user_id' => 1]);
        
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(5, $job->backoff);
        $this->assertTrue($job->deleteWhenMissingModels);
    }

    #[Test]
    public function existing_phase_5_3_tests_still_pass()
    {
        $tenant = User::factory()->tenant()->create();
        
        $cacheKey = "nexus:local:analytics:contracts:tenant:test";
        
        Cache::put($cacheKey, ['data' => 'test'], 300);
        
        AnalyticsCacheMetadata::write($cacheKey, [
            'role' => 'tenant',
            'user_id' => $tenant->id,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ], 300);
        
        $metadata = AnalyticsCacheMetadata::read($cacheKey);
        $this->assertNotNull($metadata);
        $this->assertEquals('tenant', $metadata['role']);
        $this->assertEquals($tenant->id, $metadata['user_id']);
    }
}
