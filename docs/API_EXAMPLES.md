# API Examples - Phase 1

This document provides example API usage for Phase 1 functionality.

**Note**: Actual route implementation is not included in Phase 1 deliverables. These examples show how the services should be called from controllers.

---

## Public Listing Search

### Search All Active Listings

**Request**:
```http
GET /api/listings?page=1&per_page=20
```

**Controller Implementation**:
```php
public function index(Request $request, ListingService $service)
{
    $listings = $service->searchPublicListings(
        filters: $request->all(),
        perPage: $request->get('per_page', 20)
    );
    
    return response()->json($listings);
}
```

**Response**:
```json
{
  "data": [
    {
      "id": 1,
      "title": "Beautiful 2BR/2BA in Downtown SF",
      "description": "Spacious apartment...",
      "status": "active",
      "published_at": "2024-12-20T10:00:00Z",
      "unit": {
        "bedrooms": 2,
        "bathrooms": 2,
        "rent_amount": "3500.00",
        "property": {
          "city": "San Francisco",
          "state": "CA"
        }
      },
      "primary_photo": {
        "path": "/storage/listings/photo1.jpg"
      }
    }
  ],
  "current_page": 1,
  "total": 15
}
```

---

### Search with Filters

**Request**:
```http
GET /api/listings?city=San Francisco&bedrooms=2&min_price=2000&max_price=4000&pets_allowed=1&sort_by=price_low
```

**Supported Filters**:
- `keyword` - Search in title/description
- `city` - Filter by city (partial match)
- `state` - Filter by state code (CA, NY, etc.)
- `zip_code` - Filter by zip code
- `bedrooms` - Exact bedroom count
- `min_price` - Minimum rent amount
- `max_price` - Maximum rent amount
- `property_type` - Filter by property type enum
- `pets_allowed` - Boolean filter
- `sort_by` - Sorting: `newest`, `price_low`, `price_high`, `featured`

---

### Get Single Listing

**Request**:
```http
GET /api/listings/1
```

**Controller Implementation**:
```php
public function show(int $id, ListingService $service)
{
    $listing = $service->getPublicListing($id);
    
    if (!$listing) {
        return response()->json(['message' => 'Listing not found'], 404);
    }
    
    return response()->json($listing);
}
```

**Note**: `getPublicListing()` automatically increments view count.

---

## Tenant Operations

### Save Listing

**Request**:
```http
POST /api/saved-listings
Authorization: Bearer {token}

{
  "listing_id": 1,
  "notes": "Love the location!"
}
```

**Controller Implementation**:
```php
public function store(Request $request)
{
    $validated = $request->validate([
        'listing_id' => 'required|exists:listings,id',
        'notes' => 'nullable|string|max:500'
    ]);
    
    $request->user()->savedListings()->attach(
        $validated['listing_id'],
        ['notes' => $validated['notes'] ?? null]
    );
    
    return response()->json(['message' => 'Listing saved'], 201);
}
```

---

### Get Saved Listings

**Request**:
```http
GET /api/saved-listings
Authorization: Bearer {token}
```

**Controller Implementation**:
```php
public function index(Request $request)
{
    $savedListings = $request->user()
        ->savedListings()
        ->with(['unit.property', 'primaryPhoto'])
        ->get();
    
    return response()->json($savedListings);
}
```

---

## Landlord Operations

### Get Landlord's Properties

**Request**:
```http
GET /api/landlord/properties
Authorization: Bearer {token}
```

**Controller Implementation**:
```php
public function index(Request $request)
{
    $this->authorize('viewAny', Property::class);
    
    $properties = $request->user()
        ->properties()
        ->with('units')
        ->get();
    
    return response()->json($properties);
}
```

---

### Get Enabled Features

**Request**:
```http
GET /api/landlord/features
Authorization: Bearer {token}
```

**Controller Implementation**:
```php
public function index(Request $request, FeatureGatingService $service)
{
    $features = $service->getEnabledFeatures($request->user());
    
    return response()->json($features);
}
```

**Response**:
```json
{
  "data": [
    {
      "key": "listings",
      "name": "Property Listings",
      "description": "Create and publish property listings",
      "enabled_at": "2024-12-01T08:00:00Z"
    }
  ]
}
```

---

### Check Feature Availability

**Request**:
```http
GET /api/landlord/features/payments/can-enable
Authorization: Bearer {token}
```

**Controller Implementation**:
```php
public function canEnable(string $featureKey, Request $request, FeatureGatingService $service)
{
    $check = $service->canEnableFeature($request->user(), $featureKey);
    
    return response()->json($check);
}
```

**Response (Cannot Enable)**:
```json
{
  "can_enable": false,
  "reason": "Identity verification required"
}
```

**Response (Can Enable)**:
```json
{
  "can_enable": true,
  "reason": null
}
```

---

## Admin Operations

### Dashboard Stats

**Request**:
```http
GET /api/admin/dashboard
Authorization: Bearer {admin_token}
```

**Controller Implementation**:
```php
public function dashboard()
{
    return response()->json([
        'total_users' => User::count(),
        'total_landlords' => User::landlords()->count(),
        'total_tenants' => User::tenants()->count(),
        'total_properties' => Property::count(),
        'total_units' => Unit::count(),
        'active_listings' => Listing::public()->count(),
        'pending_review' => Listing::pendingReview()->count(),
    ]);
}
```

---

### Get Pending Listings

**Request**:
```http
GET /api/admin/listings/pending
Authorization: Bearer {admin_token}
```

**Controller Implementation**:
```php
public function pending(ListingService $service)
{
    $listings = $service->getPendingReviewListings();
    return response()->json($listings);
}
```

---

### Approve Listing

**Request**:
```http
POST /api/admin/listings/1/approve
Authorization: Bearer {admin_token}
```

**Controller Implementation**:
```php
public function approve(int $id, ListingService $service, AuditService $audit)
{
    $listing = Listing::findOrFail($id);
    
    $listing = $service->publishListing($listing);
    
    $audit->logListingPublished($listing, auth('admin')->user());
    
    return response()->json($listing);
}
```

---

### Reject Listing

**Request**:
```http
POST /api/admin/listings/1/reject
Authorization: Bearer {admin_token}

{
  "reason": "Photos do not match property description"
}
```

**Controller Implementation**:
```php
public function reject(int $id, Request $request, AuditService $audit)
{
    $validated = $request->validate([
        'reason' => 'required|string|max:1000'
    ]);
    
    $listing = Listing::findOrFail($id);
    
    $listing->update([
        'status' => ListingStatus::REJECTED,
        'reviewed_by' => auth('admin')->id(),
        'reviewed_at' => now(),
        'rejection_reason' => $validated['reason'],
    ]);
    
    event(new ListingRejected($listing, $validated['reason']));
    
    $audit->logListingRejected(
        $listing,
        auth('admin')->user(),
        $validated['reason']
    );
    
    return response()->json($listing);
}
```

---

### View Audit Logs

**Request**:
```http
GET /api/admin/audit-logs?action=listing_approved&page=1
```

**Controller Implementation**:
```php
public function index(Request $request)
{
    $query = AuditLog::query()
        ->with(['actor', 'subject'])
        ->orderBy('created_at', 'desc');
    
    if ($request->has('action')) {
        $query->action($request->get('action'));
    }
    
    if ($request->has('severity')) {
        $query->where('severity', $request->get('severity'));
    }
    
    $logs = $query->paginate(50);
    
    return response()->json($logs);
}
```

---

### Enable Feature for Landlord

**Request**:
```http
POST /api/admin/landlords/5/features/payments/enable
Authorization: Bearer {admin_token}

{
  "notes": "Verified bank account and identity documents"
}
```

**Controller Implementation**:
```php
public function enableFeature(
    int $landlordId,
    string $featureKey,
    Request $request,
    FeatureGatingService $service,
    AuditService $audit
) {
    $landlord = User::landlords()->findOrFail($landlordId);
    $admin = auth('admin')->user();
    
    $validated = $request->validate([
        'notes' => 'nullable|string|max:500'
    ]);
    
    $landlordFeature = $service->enableFeature(
        $landlord,
        $featureKey,
        $admin->id
    );
    
    if (!empty($validated['notes'])) {
        $landlordFeature->update(['notes' => $validated['notes']]);
    }
    
    $audit->logFeatureEnabled($landlord, $featureKey, $admin);
    
    return response()->json($landlordFeature, 201);
}
```

---

## Authentication Examples

### User Registration

**Request**:
```http
POST /api/register

{
  "user_type": "tenant",
  "email": "newuser@example.com",
  "password": "SecurePassword123!",
  "first_name": "Jane",
  "last_name": "Doe",
  "phone": "555-0100"
}
```

**Controller Implementation**:
```php
public function register(Request $request)
{
    $validated = $request->validate([
        'user_type' => ['required', 'in:tenant,landlord'],
        'email' => 'required|email|unique:users,email',
        'password' => 'required|min:8|confirmed',
        'first_name' => 'required|string|max:255',
        'last_name' => 'required|string|max:255',
        'phone' => 'nullable|string|max:20',
    ]);
    
    $user = User::create([
        'user_type' => $validated['user_type'],
        'email' => $validated['email'],
        'password' => Hash::make($validated['password']),
        'first_name' => $validated['first_name'],
        'last_name' => $validated['last_name'],
        'phone' => $validated['phone'],
    ]);
    
    event(new UserCreated($user));
    
    $token = $user->createToken('auth_token')->plainTextToken;
    
    return response()->json([
        'user' => $user,
        'token' => $token,
    ], 201);
}
```

---

### User Login

**Request**:
```http
POST /api/login

{
  "email": "tenant1@example.com",
  "password": "password"
}
```

**Controller Implementation**:
```php
public function login(Request $request)
{
    $validated = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);
    
    if (!Auth::attempt($validated)) {
        return response()->json([
            'message' => 'Invalid credentials'
        ], 401);
    }
    
    $user = User::where('email', $validated['email'])->first();
    
    if (!$user->is_active) {
        return response()->json([
            'message' => 'Account suspended'
        ], 403);
    }
    
    $token = $user->createToken('auth_token')->plainTextToken;
    
    return response()->json([
        'user' => $user,
        'token' => $token,
    ]);
}
```

---

### Admin Login

**Request**:
```http
POST /api/admin/login

{
  "email": "admin@nexus.com",
  "password": "password"
}
```

**Controller Implementation**:
```php
public function login(Request $request, AuditService $audit)
{
    $validated = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);
    
    if (!Auth::guard('admin')->attempt($validated)) {
        return response()->json([
            'message' => 'Invalid credentials'
        ], 401);
    }
    
    $admin = Admin::where('email', $validated['email'])->first();
    
    if (!$admin->is_active) {
        return response()->json([
            'message' => 'Account inactive'
        ], 403);
    }
    
    $admin->recordLogin();
    $audit->logAdminLogin($admin);
    
    $token = $admin->createToken('admin_token')->plainTextToken;
    
    return response()->json([
        'admin' => $admin,
        'token' => $token,
    ]);
}
```

---

## Testing with Seeded Data

After running `php artisan db:seed --class=Phase1Seeder`:

### Test Public Search
```bash
curl http://localhost:8000/api/listings
```

### Test Login (Tenant)
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"tenant1@example.com","password":"password"}'
```

### Test Feature Check (Landlord)
```bash
curl http://localhost:8000/api/landlord/features \
  -H "Authorization: Bearer {your_token}"
```

---

**Last Updated**: December 2024
