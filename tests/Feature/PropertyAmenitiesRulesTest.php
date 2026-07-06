<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PropertyAmenitiesRulesTest
 *
 * Covers the new property-level amenities/rules/address_visibility fields
 * added for the Add Property wizard, plus the widened PropertyType enum.
 */
class PropertyAmenitiesRulesTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->landlord = User::factory()->landlord()->create();
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Property',
            'property_type' => PropertyType::APARTMENT->value,
            'street_address' => '123 Main St',
            'city' => 'Accra',
            'state' => 'GA',
            'zip_code' => '00233',
        ], $overrides);
    }

    public function test_landlord_can_create_property_with_amenities_rules_and_visibility()
    {
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/properties', $this->basePayload([
                'address_visibility' => 'public',
                'amenities' => ['gated', 'cctv', 'backup_generator'],
                'rules' => [
                    'pets_allowed' => true,
                    'smoking_allowed' => false,
                    'guests_allowed' => true,
                    'max_occupants' => 4,
                    'min_lease_months' => 6,
                    'quiet_hours' => '10pm - 6am',
                    'utility_responsibility' => 'tenant',
                    'maintenance_responsibility' => 'landlord',
                ],
            ]));

        $response->assertStatus(201);

        $property = Property::query()->where('landlord_id', $this->landlord->id)->firstOrFail();
        $this->assertSame('public', $property->address_visibility);
        $this->assertEqualsCanonicalizing(['gated', 'cctv', 'backup_generator'], $property->amenities);
        $this->assertSame('tenant', $property->rules['utility_responsibility']);
        $this->assertSame(4, $property->rules['max_occupants']);
    }

    public function test_property_defaults_to_area_only_visibility_when_not_specified()
    {
        $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/properties', $this->basePayload())
            ->assertStatus(201);

        $property = Property::query()->where('landlord_id', $this->landlord->id)->firstOrFail();
        $this->assertSame('area_only', $property->address_visibility);
        $this->assertNull($property->amenities);
        $this->assertNull($property->rules);
    }

    public function test_invalid_amenity_value_is_rejected()
    {
        $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/properties', $this->basePayload([
                'amenities' => ['not_a_real_amenity'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amenities.0']);
    }

    public function test_invalid_address_visibility_value_is_rejected()
    {
        $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/properties', $this->basePayload([
                'address_visibility' => 'everyone',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['address_visibility']);
    }

    public function test_invalid_rules_responsibility_value_is_rejected()
    {
        $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/properties', $this->basePayload([
                'rules' => ['utility_responsibility' => 'nobody'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rules.utility_responsibility']);
    }

    public function test_landlord_can_update_amenities_and_rules()
    {
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);

        $response = $this->actingAs($this->landlord, 'sanctum')
            ->putJson("/api/landlord/properties/{$property->id}", [
                'amenities' => ['pool', 'gym'],
                'rules' => ['pets_allowed' => false, 'max_occupants' => 2],
                'address_visibility' => 'full_after_approval',
            ]);

        $response->assertStatus(200);

        $property->refresh();
        $this->assertEqualsCanonicalizing(['pool', 'gym'], $property->amenities);
        $this->assertSame(2, $property->rules['max_occupants']);
        $this->assertSame('full_after_approval', $property->address_visibility);
    }

    #[DataProvider('newPropertyTypeProvider')]
    public function test_new_property_type_cases_are_accepted(string $type)
    {
        $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/properties', $this->basePayload(['property_type' => $type]))
            ->assertStatus(201);

        $this->assertDatabaseHas('properties', [
            'landlord_id' => $this->landlord->id,
            'property_type' => $type,
        ]);
    }

    public static function newPropertyTypeProvider(): array
    {
        return [
            'duplex' => [PropertyType::DUPLEX->value],
            'studio_block' => [PropertyType::STUDIO_BLOCK->value],
            'compound_house' => [PropertyType::COMPOUND_HOUSE->value],
            'mixed_use' => [PropertyType::MIXED_USE->value],
        ];
    }
}
