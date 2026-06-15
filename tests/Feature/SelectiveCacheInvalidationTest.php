<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Support\Cache\AnalyticsCacheMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * SelectiveCacheInvalidationTest
 *
 * Phase 5.3: Tests selective cache invalidation using metadata overlap detection.
 */
class SelectiveCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant1;

    protected User $tenant2;

    protected User $landlord;

    protected Property $property1;

    protected Property $property2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant1 = User::factory()->tenant()->create();
        $this->tenant2 = User::factory()->tenant()->create();
        $this->landlord = User::factory()->landlord()->create();

        $this->property1 = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $this->property2 = Property::factory()->create(['landlord_id' => $this->landlord->id]);
    }

    public function test_metadata_build_includes_tenant_user_id()
    {
        $metadata = AnalyticsCacheMetadata::build('tenant', [
            'user_id' => 42,
            'start_date' => '2025-01-01',
        ]);

        $this->assertEquals('tenant', $metadata['role']);
        $this->assertEquals(42, $metadata['user_id']);
        $this->assertNull($metadata['property_id']);
        $this->assertEquals('2025-01-01', $metadata['start_date']);
    }

    public function test_metadata_build_includes_landlord_property_id()
    {
        $metadata = AnalyticsCacheMetadata::build('landlord', [
            'property_id' => 12,
            'end_date' => '2025-12-31',
        ]);

        $this->assertEquals('landlord', $metadata['role']);
        $this->assertNull($metadata['user_id']);
        $this->assertEquals(12, $metadata['property_id']);
        $this->assertEquals('2025-12-31', $metadata['end_date']);
    }

    public function test_metadata_build_admin_has_no_scope_identifiers()
    {
        $metadata = AnalyticsCacheMetadata::build('admin', [
            'some_filter' => 'value',
        ]);

        $this->assertEquals('admin', $metadata['role']);
        $this->assertNull($metadata['user_id']);
        $this->assertNull($metadata['property_id']);
    }

    public function test_metadata_write_and_read()
    {
        $cacheKey = 'nexus:testing:analytics:contracts:tenant:test123';
        $metadata = [
            'role' => 'tenant',
            'user_id' => 42,
            'property_id' => null,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ];

        $result = AnalyticsCacheMetadata::write($cacheKey, $metadata, 300);
        $this->assertTrue($result);

        $retrieved = AnalyticsCacheMetadata::read($cacheKey);
        $this->assertNotNull($retrieved);
        $this->assertEquals($metadata, $retrieved);
    }

    public function test_metadata_read_returns_null_for_missing_key()
    {
        $cacheKey = 'nexus:testing:analytics:contracts:tenant:nonexistent';
        $retrieved = AnalyticsCacheMetadata::read($cacheKey);

        $this->assertNull($retrieved);
    }

    public function test_overlap_detects_matching_user_id()
    {
        $metadata = [
            'role' => 'tenant',
            'user_id' => 42,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];

        // Same user - should overlap
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata, ['user_id' => 42]));

        // Different user - should NOT overlap
        $this->assertFalse(AnalyticsCacheMetadata::overlaps($metadata, ['user_id' => 99]));
    }

    public function test_overlap_detects_matching_property_id()
    {
        $metadata = [
            'role' => 'landlord',
            'user_id' => null,
            'property_id' => 12,
            'start_date' => null,
            'end_date' => null,
        ];

        // Same property - should overlap
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata, ['property_id' => 12]));

        // Different property - should NOT overlap
        $this->assertFalse(AnalyticsCacheMetadata::overlaps($metadata, ['property_id' => 99]));
    }

    public function test_overlap_detects_date_within_range()
    {
        $metadata = [
            'role' => 'tenant',
            'user_id' => 42,
            'property_id' => null,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ];

        // Date within range - should overlap
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata, [
            'user_id' => 42,
            'date' => '2025-06-15',
        ]));

        // Date before range - should NOT overlap
        $this->assertFalse(AnalyticsCacheMetadata::overlaps($metadata, [
            'user_id' => 42,
            'date' => '2024-12-31',
        ]));

        // Date after range - should NOT overlap
        $this->assertFalse(AnalyticsCacheMetadata::overlaps($metadata, [
            'user_id' => 42,
            'date' => '2026-01-01',
        ]));
    }

    public function test_overlap_with_no_date_filters_always_overlaps()
    {
        $metadata = [
            'role' => 'tenant',
            'user_id' => 42,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];

        // No date filters in metadata - should overlap regardless of date
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata, [
            'user_id' => 42,
            'date' => '2025-06-15',
        ]));

        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata, [
            'user_id' => 42,
            'date' => '2099-12-31',
        ]));
    }

    public function test_admin_metadata_always_overlaps()
    {
        $metadata = [
            'role' => 'admin',
            'user_id' => null,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];

        // Admin always overlaps
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata, ['user_id' => 42]));
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata, ['property_id' => 12]));
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata, ['date' => '2025-06-15']));
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata, []));
    }

    public function test_contract_change_does_not_invalidate_other_tenant_cache()
    {
        // This test validates selective invalidation in practice
        // We can't easily test Redis in unit tests, but we validate the logic

        $metadata1 = [
            'role' => 'tenant',
            'user_id' => $this->tenant1->id,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];

        $metadata2 = [
            'role' => 'tenant',
            'user_id' => $this->tenant2->id,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];

        // Contract change for tenant1
        $changedData = [
            'user_id' => $this->tenant1->id,
        ];

        // Tenant1's cache should overlap
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata1, $changedData));

        // Tenant2's cache should NOT overlap
        $this->assertFalse(AnalyticsCacheMetadata::overlaps($metadata2, $changedData));
    }

    public function test_ledger_change_does_not_invalidate_other_property_cache()
    {
        $metadata1 = [
            'role' => 'landlord',
            'user_id' => null,
            'property_id' => $this->property1->id,
            'start_date' => null,
            'end_date' => null,
        ];

        $metadata2 = [
            'role' => 'landlord',
            'user_id' => null,
            'property_id' => $this->property2->id,
            'start_date' => null,
            'end_date' => null,
        ];

        // Ledger change for property1
        $changedData = [
            'property_id' => $this->property1->id,
        ];

        // Property1's cache should overlap
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata1, $changedData));

        // Property2's cache should NOT overlap
        $this->assertFalse(AnalyticsCacheMetadata::overlaps($metadata2, $changedData));
    }

    public function test_date_range_prevents_unnecessary_invalidation()
    {
        // Cache filtered for Q1 2025
        $metadataQ1 = [
            'role' => 'tenant',
            'user_id' => $this->tenant1->id,
            'property_id' => null,
            'start_date' => '2025-01-01',
            'end_date' => '2025-03-31',
        ];

        // Change in Q2 2025
        $changedDataQ2 = [
            'user_id' => $this->tenant1->id,
            'date' => '2025-04-15',
        ];

        // Q1 cache should NOT overlap with Q2 change
        $this->assertFalse(AnalyticsCacheMetadata::overlaps($metadataQ1, $changedDataQ2));
    }

    public function test_metadata_validation_rejects_invalid_structure()
    {
        // Missing required fields
        $cacheKey = 'nexus:testing:analytics:contracts:tenant:test456';
        $invalidMetadata = ['role' => 'tenant']; // Missing other fields

        AnalyticsCacheMetadata::write($cacheKey, $invalidMetadata, 300);

        // Should return null due to validation failure
        $retrieved = AnalyticsCacheMetadata::read($cacheKey);
        $this->assertNull($retrieved);
    }

    public function test_metadata_validation_rejects_invalid_role()
    {
        $cacheKey = 'nexus:testing:analytics:contracts:invalid:test789';
        $invalidMetadata = [
            'role' => 'superuser', // Invalid role
            'user_id' => null,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];

        AnalyticsCacheMetadata::write($cacheKey, $invalidMetadata, 300);

        // Should return null due to invalid role
        $retrieved = AnalyticsCacheMetadata::read($cacheKey);
        $this->assertNull($retrieved);
    }

    public function test_metadata_delete_removes_metadata()
    {
        $cacheKey = 'nexus:testing:analytics:contracts:tenant:delete_test';
        $metadata = [
            'role' => 'tenant',
            'user_id' => 42,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];

        AnalyticsCacheMetadata::write($cacheKey, $metadata, 300);
        $this->assertNotNull(AnalyticsCacheMetadata::read($cacheKey));

        AnalyticsCacheMetadata::delete($cacheKey);
        $this->assertNull(AnalyticsCacheMetadata::read($cacheKey));
    }

    public function test_complex_overlap_scenario()
    {
        // Complex scenario: tenant + date range
        $metadata = [
            'role' => 'tenant',
            'user_id' => $this->tenant1->id,
            'property_id' => null,
            'start_date' => '2025-01-01',
            'end_date' => '2025-06-30',
        ];

        // Same tenant, date in range - SHOULD overlap
        $this->assertTrue(AnalyticsCacheMetadata::overlaps($metadata, [
            'user_id' => $this->tenant1->id,
            'date' => '2025-03-15',
        ]));

        // Same tenant, date out of range - should NOT overlap
        $this->assertFalse(AnalyticsCacheMetadata::overlaps($metadata, [
            'user_id' => $this->tenant1->id,
            'date' => '2025-07-01',
        ]));

        // Different tenant, date in range - should NOT overlap
        $this->assertFalse(AnalyticsCacheMetadata::overlaps($metadata, [
            'user_id' => $this->tenant2->id,
            'date' => '2025-03-15',
        ]));
    }
}
