# NEXUS - Phase 2: Core Application Workflows

## Overview

**Phase 2** transforms Nexus from a data foundation into a fully functional application with complete user workflows.

This phase adds:
- ✅ **11 Controllers** - Complete API endpoints for all user types
- ✅ **3 Policies** - Authorization rules for ownership and permissions
- ✅ **8 Form Requests** - Validation for all inputs
- ✅ **2 Middleware** - Role enforcement (tenant/landlord)
- ✅ **Complete API Routes** - Organized by role with strict separation
- ✅ **Feature Tests** - Workflow and authorization testing

---

## What's New in Phase 2

### Public Features (No Auth)
- Search listings with comprehensive filters
- View listing details
- View featured listings

### Tenant Features (auth:sanctum + tenant)
- Tenant dashboard with statistics
- Save/unsave listings
- View saved listings with notes

### Landlord Features (auth:sanctum + landlord)
- Guided onboarding checklist
- Create/manage properties
- Create/manage units
- Create/manage listings
- Submit listings for admin review
- Feature-gated operations

### Admin Features (auth:sanctum,admin)
- Admin dashboard with system statistics
- View pending listings
- Approve/reject listings with reasons
- Enable/disable landlord features
- View audit logs with filters

---

## Architecture

### Middleware

**EnsureTenant** (`app/Http/Middleware/EnsureTenant.php`)
- Verifies authenticated user is a tenant
- Returns 403 if user is landlord or admin
- Applied to `/api/tenant/*` routes

**EnsureLandlord** (`app/Http/Middleware/EnsureLandlord.php`)
- Verifies authenticated user is a landlord
- Returns 403 if user is tenant or admin
- Applied to `/api/landlord/*` routes

### Policies

**PropertyPolicy** (`app/Policies/PropertyPolicy.php`)
- Landlords can only view/edit their own properties
- Cannot delete properties with units
- Tenants cannot create properties

**UnitPolicy** (`app/Policies/UnitPolicy.php`)
- Landlords can only manage units in their properties
- Cannot delete units with active listings

**ListingPolicy** (`app/Policies/ListingPolicy.php`)
- Landlords can only manage their own listings
- Editing restricted based on status (editable statuses only)
- Can only submit draft listings
- Cannot delete active or pending listings

### Form Requests

All form requests validate and authorize in one place:

1. **StorePropertyRequest** - Property creation validation
2. **UpdatePropertyRequest** - Property update validation  
3. **StoreUnitRequest** - Unit creation validation
4. **UpdateUnitRequest** - Unit update validation
5. **StoreListingRequest** - Listing creation validation
6. **UpdateListingRequest** - Listing update validation
7. **SubmitListingRequest** - Listing submission validation (checks completeness)
8. **RejectListingRequest** - Rejection reason validation (min 20 chars)

---

## API Endpoints

### Public Routes (No Auth)

```
GET  /api/listings                # Search listings
GET  /api/listings/featured       # Featured listings
GET  /api/listings/{id}           # Listing details
```

**Filters Supported**:
- `keyword` - Search in title/description
- `city`, `state`, `zip_code` - Location
- `min_price`, `max_price` - Price range
- `bedrooms`, `bathrooms` - Unit specs
- `property_type` - Property type filter
- `pets_allowed` - Boolean filter
- `sort_by` - newest, price_low, price_high, featured

### Tenant Routes (auth:sanctum + tenant)

```
GET  /api/tenant/dashboard                  # Dashboard stats
GET  /api/tenant/saved-listings             # Get saved listings
POST /api/tenant/listings/{listing}/save    # Save listing
DELETE /api/tenant/listings/{listing}/save  # Unsave listing
```

### Landlord Routes (auth:sanctum + landlord)

```
# Onboarding
GET  /api/landlord/onboarding               # Checklist status

# Properties
GET  /api/landlord/properties               # List properties
POST /api/landlord/properties               # Create property
GET  /api/landlord/properties/{id}          # View property
PUT  /api/landlord/properties/{id}          # Update property
DELETE /api/landlord/properties/{id}        # Delete property

# Units
GET  /api/landlord/units                    # List all units
POST /api/landlord/properties/{id}/units    # Create unit
GET  /api/landlord/units/{id}               # View unit
PUT  /api/landlord/units/{id}               # Update unit
DELETE /api/landlord/units/{id}             # Delete unit

# Listings
GET  /api/landlord/listings                 # List listings
POST /api/landlord/units/{id}/listings      # Create listing (draft)
GET  /api/landlord/listings/{id}            # View listing
PUT  /api/landlord/listings/{id}            # Update listing
POST /api/landlord/listings/{id}/submit     # Submit for review
DELETE /api/landlord/listings/{id}          # Delete listing
```

### Admin Routes (auth:sanctum,admin)

```
# Dashboard
GET  /api/admin/dashboard                   # System stats

# Listing Moderation
GET  /api/admin/listings/pending            # Pending listings
POST /api/admin/listings/{id}/approve       # Approve listing
POST /api/admin/listings/{id}/reject        # Reject listing

# Feature Management
GET  /api/admin/landlords/{id}/features           # View features
POST /api/admin/landlords/{id}/features/{key}/enable   # Enable feature
POST /api/admin/landlords/{id}/features/{key}/disable  # Disable feature

# Audit Logs
GET  /api/admin/audit-logs                  # List audit logs
GET  /api/admin/audit-logs/{id}             # View audit log
```

---

## Complete Workflows

### Workflow 1: Landlord Creates First Listing

1. **Landlord logs in** and checks onboarding status
   ```
   GET /api/landlord/onboarding
   ```

2. **Creates property**
   ```
   POST /api/landlord/properties
   {
     "name": "Sunset Apartments",
     "property_type": "apartment",
     "street_address": "123 Main St",
     "city": "San Francisco",
     "state": "CA",
     "zip_code": "94102"
   }
   ```

3. **Creates unit**
   ```
   POST /api/landlord/properties/{property_id}/units
   {
     "unit_number": "101",
     "bedrooms": 2,
     "bathrooms": 2,
     "rent_amount": 3500,
     "security_deposit": 3500,
     "availability_status": "available"
   }
   ```

4. **Creates listing** (draft status)
   ```
   POST /api/landlord/units/{unit_id}/listings
   {
     "title": "Beautiful 2BR in Downtown SF",
     "description": "Spacious apartment with modern amenities...",
     "pets_allowed": false
   }
   ```

5. **Submits for review**
   ```
   POST /api/landlord/listings/{listing_id}/submit
   ```
   → Listing status changes to `pending_review`
   → Admin receives notification email (via event)

6. **Admin reviews and approves**
   ```
   POST /api/admin/listings/{listing_id}/approve
   ```
   → Listing status changes to `active`
   → Landlord receives notification email (via event)

7. **Listing is now public**
   ```
   GET /api/listings/{listing_id}  # Anyone can view
   ```

### Workflow 2: Tenant Saves Listing

1. **Tenant searches listings**
   ```
   GET /api/listings?city=San Francisco&bedrooms=2&max_price=4000
   ```

2. **Views listing details**
   ```
   GET /api/listings/{listing_id}
   ```
   → View count increments automatically

3. **Saves listing**
   ```
   POST /api/tenant/listings/{listing_id}/save
   {
     "notes": "Love the location!"
   }
   ```

4. **Views saved listings**
   ```
   GET /api/tenant/saved-listings
   ```

### Workflow 3: Admin Enables Feature

1. **Admin views landlord's features**
   ```
   GET /api/admin/landlords/{landlord_id}/features
   ```
   → Shows enabled/disabled features
   → Shows if landlord can enable each feature (prerequisites)

2. **Admin enables payments feature**
   ```
   POST /api/admin/landlords/{landlord_id}/features/payments/enable
   {
     "notes": "Verified bank account on file"
   }
   ```
   → Feature enabled for landlord
   → Audit log created
   → Landlord receives notification email

---

## Feature Gating Enforcement

### How It Works

```php
// In LandlordListingController
$this->featureGatingService->requireFeature($request->user(), 'listings');
```

This checks:
1. Does feature exist?
2. Is feature available system-wide?
3. Is landlord's identity verified (if required)?
4. Does landlord have feature enabled in `landlord_features`?

If any check fails → `Exception` thrown → 500 error

### Available Features (from Phase 1 Seeder)

- `listings` - Create and publish property listings (no verification required)
- `applications` - Accept rental applications (requires identity verification)
- `payments` - Collect rent online (requires identity verification)
- `leases` - Digital lease signing (requires identity verification)
- `maintenance` - Maintenance request tracking (no verification required)

---

## Testing

### Running Tests

```bash
# Run all Phase 2 tests
php artisan test --testsuite=Feature

# Run specific test
php artisan test --filter=ListingSubmissionWorkflowTest

# Run with coverage
php artisan test --coverage
```

### Test Coverage

**ListingSubmissionWorkflowTest** tests:
- ✅ Landlord can create property
- ✅ Landlord can create unit
- ✅ Complete workflow: Create → Submit → Approve → Public
- ✅ Feature gating enforcement
- ✅ Tenant cannot access landlord routes
- ✅ Landlord cannot access tenant routes

**PolicyAuthorizationTest** tests:
- ✅ Landlords can only view own properties
- ✅ Landlords can only update own units
- ✅ Cannot edit pending listings
- ✅ Can edit draft listings
- ✅ Can only submit draft listings
- ✅ Tenants cannot create properties
- ✅ Cannot delete properties with units

---

## Event Flow

All side effects are event-driven:

### Existing Events (Phase 1)
- `UserCreated` → Welcome email + Audit log
- `EmailVerified` → Confirmation email + Audit log
- `IdentityVerified` → Notification email + Audit log
- `ListingPublished` → Notification email + Audit log

### New Events (Phase 2)
- `ListingSubmittedForReview` → Admin notification
- (Future) `FeatureEnabled` → Landlord notification
- (Future) `FeatureDisabled` → Landlord notification

---

## Authorization Flow

```
Request → Middleware (tenant/landlord) → Controller → Policy Check → Service Layer → Response
```

**Example**:

```
POST /api/landlord/listings/123/submit
  ↓
auth:sanctum (authenticates user)
  ↓
landlord middleware (ensures user is landlord)
  ↓
LandlordListingController@submit
  ↓
Policy: $this->authorize('submit', $listing)
  ↓
ListingPolicy::submit() checks:
  - Is this user the owner?
  - Is listing in DRAFT status?
  ↓
If authorized → Continue
If not → 403 Forbidden
```

---

## Validation Flow

```
Request → Form Request (validate + authorize) → Controller → Service
```

**Example**:

```
POST /api/landlord/units/{property}/create
  ↓
StoreUnitRequest validates:
  - bedrooms: required, numeric, 0-20
  - rent_amount: required, numeric, min:0
  - availability_status: required, enum
  ↓
StoreUnitRequest authorizes:
  - Can user view this property?
  ↓
If valid → Controller receives validated data
If invalid → 422 with errors
```

---

## Error Handling

### Common Errors

**401 Unauthenticated**
```json
{
  "message": "Unauthenticated."
}
```

**403 Forbidden (Wrong Role)**
```json
{
  "message": "This action is only available to landlords."
}
```

**403 Forbidden (Not Authorized)**
```json
{
  "message": "This action is unauthorized."
}
```

**422 Validation Error**
```json
{
  "message": "The title field is required.",
  "errors": {
    "title": ["The title field is required."]
  }
}
```

**500 Feature Not Enabled**
```json
{
  "message": "Feature 'payments' is not enabled for this account"
}
```

---

## Database Changes

No new migrations in Phase 2 - all use Phase 1 schema.

---

## What's NOT in Phase 2

Deferred to later phases:
- ❌ Actual email sending (Mail::send) - logs intent only
- ❌ File uploads (listing photos)
- ❌ Application system implementation
- ❌ Payment processing
- ❌ Lease management
- ❌ Maintenance requests
- ❌ Messaging implementation (schema exists)
- ❌ Frontend UI/styling
- ❌ Admin RBAC (only Super Admin)

---

## Phase 2 Completion Checklist

✅ **Controllers**: 11 created
- Public: PublicListingController
- Tenant: TenantDashboardController, SavedListingController
- Landlord: LandlordOnboardingController, PropertyController, UnitController, LandlordListingController
- Admin: AdminDashboardController, AdminListingModerationController, AdminFeatureController, AdminAuditController

✅ **Policies**: 3 created
- PropertyPolicy, UnitPolicy, ListingPolicy

✅ **Form Requests**: 8 created
- StorePropertyRequest, UpdatePropertyRequest
- StoreUnitRequest, UpdateUnitRequest
- StoreListingRequest, UpdateListingRequest
- SubmitListingRequest, RejectListingRequest

✅ **Middleware**: 2 created
- EnsureTenant, EnsureLandlord

✅ **Routes**: Complete API structure

✅ **Tests**: Workflow + Policy tests

✅ **Documentation**: Complete Phase 2 README

---

## Definition of Done

Phase 2 is DONE when:

✅ A landlord can:
- Login → Create property → Create unit → Create draft listing → Submit → (Wait for admin)

✅ An admin can:
- Login → View pending → Approve → Listing goes public

✅ A tenant can:
- View public listings → Save favorites → View saved

✅ All tests pass

✅ All routes work with proper authorization

---

**Phase 2 Status**: ✅ COMPLETE  
**Next Phase**: Phase 3 - Contracts & Payments  
**Last Updated**: December 2024
