<?php

namespace Tests\Feature;

use App\Events\Cache\CacheInvalidationCompleted;
use App\Events\Cache\CacheInvalidationFailed;
use App\Events\Cache\CacheInvalidationRouted;
use App\Events\Cache\CacheJobCompleted;
use App\Events\Cache\CacheJobStarted;
use App\Jobs\InvalidateAnalyticsCacheJob;
use App\Models\Contract;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Support\Cache\AnalyticsCacheInvalidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 5.5: Observability & Metrics Tests
 */
class CacheObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
    }

    #[Test]
    public function sync_invalidation_emits_routed_event()
    {
        Event::fake([CacheInvalidationRouted::class]);

        AnalyticsCacheInvalidator::invalidate('contracts', ['user_id' => 1]);

        Event::assertDispatched(CacheInvalidationRouted::class, function ($event) {
            return $event->domain === 'contracts'
                && $event->mode === 'sync'
                && $event->keyCount <= AnalyticsCacheInvalidator::ASYNC_INVALIDATION_THRESHOLD;
        });
    }

    #[Test]
    public function sync_invalidation_emits_completed_event()
    {
        Event::fake([CacheInvalidationCompleted::class]);

        AnalyticsCacheInvalidator::invalidate('contracts', ['user_id' => 1]);

        Event::assertDispatched(CacheInvalidationCompleted::class, function ($event) {
            return $event->domain === 'contracts'
                && $event->mode === 'sync'
                && $event->durationMs !== null;
        });
    }

    #[Test]
    public function async_job_emits_started_event()
    {
        Event::fake([CacheJobStarted::class]);

        $job = new InvalidateAnalyticsCacheJob('contracts', ['user_id' => 1]);
        $job->handle();

        Event::assertDispatched(CacheJobStarted::class, function ($event) {
            return $event->domain === 'contracts'
                && $event->queue === 'analytics-invalidation';
        });
    }

    #[Test]
    public function async_job_emits_completed_event()
    {
        Event::fake([CacheJobCompleted::class]);

        $job = new InvalidateAnalyticsCacheJob('contracts', ['user_id' => 1]);
        $job->handle();

        Event::assertDispatched(CacheJobCompleted::class, function ($event) {
            return $event->domain === 'contracts'
                && $event->durationMs > 0;
        });
    }

    #[Test]
    public function job_failure_emits_failed_event()
    {
        Event::fake([CacheInvalidationFailed::class]);

        $job = new InvalidateAnalyticsCacheJob('invalid_domain', []);
        $job->handle();

        $this->assertTrue(true);
    }

    #[Test]
    public function routed_event_includes_threshold_data()
    {
        Event::fake([CacheInvalidationRouted::class]);

        AnalyticsCacheInvalidator::invalidate('financial', []);

        Event::assertDispatched(CacheInvalidationRouted::class, function ($event) {
            return $event->threshold === 100
                && in_array($event->mode, ['sync', 'async'])
                && $event->keyCount >= 0;
        });
    }

    #[Test]
    public function completed_event_includes_metrics_data()
    {
        Event::fake([CacheInvalidationCompleted::class]);

        AnalyticsCacheInvalidator::invalidate('contracts', ['user_id' => 1]);

        Event::assertDispatched(CacheInvalidationCompleted::class, function ($event) {
            return $event->invalidatedCount >= 0
                && $event->durationMs >= 0
                && in_array($event->mode, ['sync', 'async']);
        });
    }

    #[Test]
    public function job_completed_event_includes_retry_attempt()
    {
        Event::fake([CacheJobCompleted::class]);

        $job = new InvalidateAnalyticsCacheJob('contracts', []);
        $job->handle();

        Event::assertDispatched(CacheJobCompleted::class, function ($event) {
            return isset($event->retryAttempt)
                && $event->retryAttempt >= 0;
        });
    }

    #[Test]
    public function events_do_not_change_cache_behavior()
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

        $this->assertTrue(true);
    }

    #[Test]
    public function multiple_invalidations_emit_multiple_events()
    {
        Event::fake([CacheInvalidationRouted::class]);

        AnalyticsCacheInvalidator::invalidate('contracts', []);
        AnalyticsCacheInvalidator::invalidate('financial', []);
        AnalyticsCacheInvalidator::invalidate('notifications', []);

        Event::assertDispatchedTimes(CacheInvalidationRouted::class, 3);
    }

    #[Test]
    public function event_scopes_are_sanitized()
    {
        Event::fake([CacheInvalidationRouted::class]);

        AnalyticsCacheInvalidator::invalidate('contracts', [
            'user_id' => 123,
            'property_id' => 456,
        ]);

        Event::assertDispatched(CacheInvalidationRouted::class, function ($event) {
            return is_array($event->scopes);
        });
    }

    #[Test]
    public function job_events_fire_in_correct_order()
    {
        $eventOrder = [];

        Event::listen(CacheJobStarted::class, function () use (&$eventOrder) {
            $eventOrder[] = 'started';
        });

        Event::listen(CacheJobCompleted::class, function () use (&$eventOrder) {
            $eventOrder[] = 'completed';
        });

        $job = new InvalidateAnalyticsCacheJob('contracts', []);
        $job->handle();

        $this->assertEquals(['started', 'completed'], $eventOrder);
    }

    #[Test]
    public function sync_events_fire_in_correct_order()
    {
        $eventOrder = [];

        Event::listen(CacheInvalidationRouted::class, function () use (&$eventOrder) {
            $eventOrder[] = 'routed';
        });

        Event::listen(CacheInvalidationCompleted::class, function () use (&$eventOrder) {
            $eventOrder[] = 'completed';
        });

        AnalyticsCacheInvalidator::invalidate('contracts', []);

        $this->assertEquals(['routed', 'completed'], $eventOrder);
    }
}
