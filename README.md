# NEXUS - Phase 1: Foundation

## Overview

**Nexus** is a dual-sided, governed property platform built with disciplined architecture and compliance-first design.

This is **Phase 1: Foundation**, which establishes:
- ✅ Multi-role authentication system (Tenant, Landlord, Admin)
- ✅ Role separation and access control
- ✅ Complete property/unit/listing schema
- ✅ Public listing search & filters
- ✅ Feature gating infrastructure
- ✅ Event-driven email system
- ✅ Audit logging for compliance
- ✅ Messaging infrastructure (schema only)

## Technology Stack

- **Backend**: PHP 8.3 + Laravel 11.x
- **Database**: PostgreSQL
- **Auth**: Laravel Sanctum
- **Queues**: Laravel Queues (Redis recommended)
- **Architecture**: Domain-oriented, service-based

## Directory Structure

```
nexus-phase1/
├── app/
│   ├── Enums/              # State enums (UserType, ListingStatus, etc.)
│   ├── Events/             # Domain events
│   ├── Listeners/          # Event listeners (email, audit)
│   ├── Models/             # Eloquent models
│   ├── Services/           # Business logic layer
│   └── Policies/           # Authorization policies (Phase 2)
├── database/
│   ├── migrations/         # All database migrations
│   └── seeders/            # Phase1Seeder with test data
└── docs/                   # Additional documentation
```

## Database Schema

### Core Tables

#### Authentication & Roles
- **users** - Tenants and Landlords (separate from admins)
- **admins** - Super Admin only (RBAC in Phase 4)

#### Property Management
- **properties** - Owned by landlords
- **units** - Belong to properties, are the rentable space
- **listings** - Public-facing representation of units

#### Feature Gating
- **features** - Master list of gateable features
- **landlord_features** - Per-landlord feature enablement with audit trail

#### Messaging (Schema Only - Phase 2 Implementation)
- **conversations** - Polymorphic conversation container
- **messages** - Individual messages

#### Infrastructure
- **listing_photos** - Photos for listings
- **saved_listings** - Tenants can save listings
- **audit_logs** - Immutable audit trail
- **email_logs** - Email tracking and debugging

## Key Architectural Decisions

### 1. Role Separation (Non-Negotiable)

```php
// CORRECT: Separate tables
User::where('user_type', 'landlord')  // Landlords
User::where('user_type', 'tenant')   // Tenants
Admin::query()                        // Admins (completely separate)

// Users and Admins NEVER mix
// Each has distinct authentication flows
// Each has distinct authorization rules
```

### 2. Feature Gating (Backend-Enforced)

```php
// Check if landlord can use a feature
$featureService = app(FeatureGatingService::class);

if (!$featureService->hasFeature($landlord, 'applications')) {
    throw new Exception('Applications feature not enabled');
}

// Features require:
// 1. Feature exists and is available
// 2. Landlord has feature enabled in landlord_features
// 3. Identity verification if required
// 4. Dependent features if specified
```

### 3. Event-Driven Side Effects

```php
// WRONG: Manual email sending in controller
Mail::to($user)->send(new WelcomeEmail($user));

// CORRECT: Fire event, let listeners handle side effects
event(new UserCreated($user));

// Listeners automatically:
// - Send welcome email
// - Log to audit trail
// - Trigger other workflows
```

### 4. Service Layer for Business Logic

```php
// WRONG: Fat controller
public function store(Request $request) {
    $listing = Listing::create($request->all());
    $listing->status = 'active';
    $listing->published_at = now();
    $listing->save();
    Mail::to($listing->landlord)->send(...);
    return response()->json($listing);
}

// CORRECT: Thin controller, service handles logic
public function store(Request $request, ListingService $service) {
    $validated = $request->validate(...);
    $listing = $service->createListing($validated);
    return response()->json($listing);
}
```

### 5. Audit Trail for All Critical Actions

```php
// Every admin action MUST be audited
$auditService->log(
    actor: $admin,
    action: 'listing_approved',
    subject: $listing,
    description: "Approved listing: {$listing->title}",
    severity: 'warning'
);

// Audit logs are:
// - Immutable (no updates)
// - Timestamped
// - Linked to actor and subject
// - Searchable by action/severity
```

## Enums (State Management)

All states are managed via PHP 8.1+ enums:

```php
UserType:
  - TENANT
  - LANDLORD

ListingStatus:
  - DRAFT
  - PENDING_REVIEW
  - ACTIVE
  - INACTIVE
  - REJECTED
  - ARCHIVED

PropertyType:
  - SINGLE_FAMILY
  - MULTI_FAMILY
  - APARTMENT
  - CONDO
  - TOWNHOUSE
  - COMMERCIAL
  - OTHER

UnitAvailabilityStatus:
  - AVAILABLE
  - OCCUPIED
  - PENDING
  - MAINTENANCE
  - UNLISTED
```

## Services

### ListingService
Handles all listing operations:
- `searchPublicListings()` - Public search with filters
- `getPublicListing()` - Get single listing (with view tracking)
- `createListingAsAdmin()` - Admin-only creation for Phase 1
- `publishListing()` - Publish draft to active
- `getFeaturedListings()` - Get featured listings for homepage

### FeatureGatingService
Backend enforcement of feature flags:
- `hasFeature()` - Check if landlord has feature enabled
- `requireFeature()` - Require feature or throw exception
- `canEnableFeature()` - Check if feature can be enabled (prerequisites)
- `enableFeature()` - Admin action to enable feature
- `disableFeature()` - Admin action to disable feature

### AuditService
Centralized audit logging:
- `log()` - Generic audit log creation
- `logUserCreated()` - Log user registration
- `logEmailVerified()` - Log email verification
- `logIdentityVerified()` - Log identity verification (admin action)
- `logListingPublished()` - Log listing publication
- `logFeatureEnabled()` - Log feature enablement
- `logAdminLogin()` - Log admin authentication
- `logSecurityEvent()` - Log security-related events

## Events & Listeners

### User Events
- `UserCreated` → `SendWelcomeEmail`, `LogUserCreated`
- `EmailVerified` → `SendEmailVerifiedNotification`, `LogEmailVerified`
- `IdentityVerified` → `SendIdentityVerifiedNotification`, `LogIdentityVerified`

### Listing Events
- `ListingPublished` → `SendListingPublishedNotification`, `LogListingPublished`
- `ListingSubmittedForReview` → `NotifyAdminOfListingSubmission`
- `ListingRejected` → `SendListingRejectedNotification`, `LogListingRejected`

All listeners implement `ShouldQueue` for asynchronous processing.

## Installation & Setup

### 1. Install Dependencies
```bash
composer install
```

### 2. Environment Configuration
```bash
cp .env.example .env
php artisan key:generate
```

Configure `.env`:
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nexus
DB_USERNAME=your_user
DB_PASSWORD=your_password

QUEUE_CONNECTION=redis
MAIL_MAILER=smtp
```

### 3. Run Migrations
```bash
php artisan migrate
```

### 4. Seed Database
```bash
php artisan db:seed --class=Phase1Seeder
```

This creates:
- 1 Super Admin: `admin@nexus.com`
- 2 Landlords: `landlord1@example.com` (verified), `landlord2@example.com` (unverified)
- 3 Tenants: `tenant1@example.com`, `tenant2@example.com`, `tenant3@example.com`
- 3 Properties with 4 units
- 4 Listings (3 active, 1 draft)
- 5 Core features

**All passwords**: `password`

### 5. Start Queue Worker (Required for Emails)
```bash
php artisan queue:work
```

### 6. Start Development Server
```bash
php artisan serve
```

## API Routes (Example Implementation Needed)

Phase 1 focuses on backend structure. Routes should be added following this pattern:

```php
// Public Routes
GET  /api/listings              # Search listings
GET  /api/listings/{id}         # Get single listing

// Tenant Routes (auth:sanctum, verified)
POST /api/saved-listings        # Save listing
GET  /api/saved-listings        # Get saved listings

// Landlord Routes (auth:sanctum, verified, landlord)
GET  /api/landlord/properties   # Get properties
GET  /api/landlord/listings     # Get listings
GET  /api/landlord/features     # Get enabled features

// Admin Routes (auth:sanctum, admin)
GET  /api/admin/dashboard       # Dashboard stats
GET  /api/admin/listings/pending # Pending review
POST /api/admin/listings/{id}/approve
POST /api/admin/listings/{id}/reject
GET  /api/admin/audit-logs      # Audit logs
```

## Testing Workflows

### Public Listing Search
1. Visit `/api/listings` (no auth required)
2. Apply filters: `?city=San Francisco&bedrooms=2&min_price=2000&max_price=4000`
3. Sort: `?sort_by=price_low`
4. Verify only active listings appear

### Feature Gating
1. Login as `landlord2@example.com` (unverified)
2. Attempt to enable `payments` feature
3. Should fail: "Identity verification required"
4. Login as admin, verify landlord identity
5. Now landlord can enable payments

### Audit Trail
1. Login as admin
2. Approve a listing
3. Query audit logs: action = 'listing_approved'
4. Verify log includes admin ID, listing ID, timestamp

## Phase 1 Scope Boundaries

### ✅ Included
- Full authentication for all roles
- Property/Unit/Listing schema and models
- Public search & filters
- Feature gating infrastructure
- Email event system
- Audit logging
- Messaging schema

### ❌ Excluded (Future Phases)
- **Phase 2**: Landlord listing creation UI, applications, tenant dashboards
- **Phase 3**: Leases, payments, financial tracking
- **Phase 4**: Maintenance, admin RBAC, moderation tools

## Code Quality Standards

### Models
- Use enums for all state fields
- Include scopes for common queries
- Relationships should be explicit
- Use soft deletes where appropriate

### Services
- One service per domain (Listing, Feature, Audit, etc.)
- Public methods only
- Type hints on all parameters
- Return types declared
- Exceptions for business rule violations

### Events & Listeners
- Events are dumb data carriers
- Listeners do the work
- All listeners should queue
- One listener per side effect

### Migrations
- Never edit after deployment
- Use descriptive names
- Include indexes for foreign keys and search fields
- Add comments for complex fields

## What's Next (Phase 2)

Phase 2 will add:
1. Landlord listing creation workflow
2. Guided landlord setup
3. Tenant dashboards
4. Saved listings UI
5. Basic messaging implementation
6. Application system (structure only)

## Support & Questions

For Phase 1 implementation questions:
1. Review this README thoroughly
2. Check service method documentation
3. Examine seeded data for examples
4. Verify event → listener flows

## Critical Reminders

1. **Never bypass feature gating** - Always check via `FeatureGatingService`
2. **Always fire events** - Never send emails directly from controllers
3. **Always audit admin actions** - Use `AuditService` for all critical operations
4. **Respect role separation** - Users and Admins are completely separate
5. **Soft delete legal/financial data** - Never hard delete users, properties, listings

---

**Phase 1 Status**: ✅ Complete  
**Next Phase**: Phase 2 - Tenant & Landlord Core  
**Last Updated**: December 2024
