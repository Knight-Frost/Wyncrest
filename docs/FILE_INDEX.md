# Nexus Phase 1 - File Index

Complete index of all files delivered in Phase 1.

---

## Database Migrations (13 files)

Located in: `database/migrations/`

1. `2024_01_01_000001_create_users_table.php`
   - Users table (tenants + landlords)
   - Includes identity verification fields
   - Soft deletes enabled

2. `2024_01_01_000002_create_admins_table.php`
   - Admins table (Super Admin only in Phase 1)
   - Completely separate from users

3. `2024_01_01_000003_create_properties_table.php`
   - Properties owned by landlords
   - Denormalized address fields
   - Soft deletes enabled

4. `2024_01_01_000004_create_units_table.php`
   - Units belong to properties
   - Rentable space with pricing
   - Soft deletes enabled

5. `2024_01_01_000005_create_listings_table.php`
   - Public-facing listing representation
   - Moderation workflow support
   - Soft deletes enabled

6. `2024_01_01_000006_create_listing_photos_table.php`
   - Photos for listings
   - S3-ready storage abstraction

7. `2024_01_01_000007_create_features_table.php`
   - Master list of gateable features
   - Feature requirements and dependencies

8. `2024_01_01_000008_create_landlord_features_table.php`
   - Per-landlord feature enablement
   - Full audit trail of enablement/disablement

9. `2024_01_01_000009_create_conversations_table.php`
   - Conversation container (polymorphic)
   - Schema only (Phase 2 implementation)
   - Soft deletes enabled

10. `2024_01_01_000010_create_messages_table.php`
    - Individual messages
    - Schema only (Phase 2 implementation)
    - Soft deletes enabled

11. `2024_01_01_000011_create_audit_logs_table.php`
    - Immutable audit trail
    - No updated_at column (insert-only)

12. `2024_01_01_000012_create_saved_listings_table.php`
    - Tenant saved listings (many-to-many)

13. `2024_01_01_000013_create_email_logs_table.php`
    - Email tracking and debugging
    - Delivery status monitoring

---

## Enums (4 files)

Located in: `app/Enums/`

1. `UserType.php`
   - TENANT, LANDLORD
   - Helper methods: `isLandlord()`, `isTenant()`

2. `ListingStatus.php`
   - DRAFT, PENDING_REVIEW, ACTIVE, INACTIVE, REJECTED, ARCHIVED
   - Helper methods: `isPublic()`, `isEditable()`, `requiresReview()`

3. `PropertyType.php`
   - SINGLE_FAMILY, MULTI_FAMILY, APARTMENT, CONDO, TOWNHOUSE, COMMERCIAL, OTHER
   - Helper methods: `label()`, `options()`

4. `UnitAvailabilityStatus.php`
   - AVAILABLE, OCCUPIED, PENDING, MAINTENANCE, UNLISTED
   - Helper methods: `canBeListed()`, `isRentable()`

---

## Models (7 files + 1 shared)

Located in: `app/Models/`

1. `User.php`
   - Tenants and Landlords
   - Relationships: properties, listings, enabledFeatures, savedListings
   - Scopes: landlords(), tenants(), active()

2. `Admin.php`
   - Super Admin authentication
   - Relationships: reviewedListings, enabledFeatures
   - Methods: isSuperAdmin(), recordLogin()

3. `Property.php`
   - Property management
   - Relationships: landlord, units, activeUnits
   - Scopes: active(), inCity(), inState(), inZipCode()

4. `Unit.php`
   - Rentable units
   - Relationships: property, listings, activeListing
   - Scopes: active(), available(), priceBetween(), withBedrooms()

5. `Listing.php`
   - Public listings
   - Relationships: unit, landlord, reviewer, photos, savedByUsers
   - Scopes: public(), pendingReview(), featured(), search()

6. `Feature.php` (includes LandlordFeature)
   - Feature gating master list
   - Relationships: landlords, enabledForLandlords
   - LandlordFeature pivot model with audit fields

7. `SharedModels.php`
   - ListingPhoto
   - AuditLog
   - EmailLog
   - Conversation
   - Message

---

## Services (3 files)

Located in: `app/Services/`

1. `ListingService.php`
   - Business logic for listings
   - Methods:
     - searchPublicListings()
     - getPublicListing()
     - createListingAsAdmin()
     - publishListing()
     - getPendingReviewListings()
     - getFeaturedListings()

2. `FeatureGatingService.php`
   - Backend feature enforcement
   - Methods:
     - hasFeature()
     - requireFeature()
     - getEnabledFeatures()
     - canEnableFeature()
     - enableFeature()
     - disableFeature()

3. `AuditService.php`
   - Centralized audit logging
   - Methods:
     - log() (generic)
     - logUserCreated()
     - logEmailVerified()
     - logIdentityVerified()
     - logListingPublished()
     - logListingRejected()
     - logFeatureEnabled()
     - logAdminLogin()
     - logSecurityEvent()

---

## Events (2 files)

Located in: `app/Events/`

1. `UserEvents.php`
   - UserCreated
   - EmailVerified
   - IdentityVerified

2. `ListingEvents.php`
   - ListingPublished
   - ListingSubmittedForReview
   - ListingRejected

---

## Listeners (2 files)

Located in: `app/Listeners/`

1. `EmailListeners.php`
   - SendWelcomeEmail
   - SendEmailVerifiedNotification
   - SendIdentityVerifiedNotification
   - SendListingPublishedNotification
   - NotifyAdminOfListingSubmission
   - SendListingRejectedNotification

2. `AuditListeners.php`
   - LogUserCreated
   - LogEmailVerified
   - LogIdentityVerified
   - LogListingPublished
   - LogListingRejected

---

## Providers (1 file)

Located in: `app/Providers/`

1. `EventServiceProvider.php`
   - Registers all event-listener mappings
   - Explicit registration (no auto-discovery)

---

## Seeders (1 file)

Located in: `database/seeders/`

1. `Phase1Seeder.php`
   - Creates Super Admin
   - Creates 2 Landlords (1 verified, 1 unverified)
   - Creates 3 Tenants
   - Creates 3 Properties
   - Creates 4 Units
   - Creates 4 Listings
   - Creates 5 Core Features
   - Sets up saved listings
   - All passwords: `password`

---

## Documentation (4 files)

Located in: `docs/`

1. `ARCHITECTURE.md`
   - Architectural Decision Records (ADRs)
   - 10 key decisions explained
   - Rationale and consequences

2. `API_EXAMPLES.md`
   - Complete API usage examples
   - Controller implementation patterns
   - Request/response examples
   - Authentication examples

3. `DEPLOYMENT.md`
   - Complete deployment checklist
   - Environment setup
   - Security hardening
   - Rollback procedures
   - Monitoring setup

4. `FILE_INDEX.md` (this file)
   - Complete file inventory
   - File purposes and locations

---

## Root Documentation (1 file)

Located in root: `/`

1. `README.md`
   - Phase 1 overview
   - Technology stack
   - Installation instructions
   - Database schema explanation
   - Key architectural decisions
   - Service documentation
   - Event system documentation
   - Seeder credentials
   - Phase scope boundaries

---

## Summary

**Total Files**: 38

### By Type:
- Migrations: 13
- Enums: 4
- Models: 8 (7 individual + 1 shared file)
- Services: 3
- Events: 2
- Listeners: 2
- Providers: 1
- Seeders: 1
- Documentation: 5

### Lines of Code (Approximate):
- Migrations: ~1,300
- Models: ~1,100
- Services: ~450
- Events/Listeners: ~400
- Enums: ~200
- Seeders: ~300
- Documentation: ~2,500
- **Total: ~6,250 lines**

---

## What's Missing (Intentionally)

Phase 1 does NOT include:
- Controllers (examples provided in docs)
- Routes (structure provided in docs)
- Policies (Phase 2)
- Form Requests/Validation (Phase 2)
- Mailables (stubs in listeners)
- Tests (Phase 2+)
- Frontend assets (separate project)

---

**Last Updated**: December 2024
