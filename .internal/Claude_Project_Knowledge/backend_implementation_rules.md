# Backend Implementation Rules for Nexus

Rules for working in the Nexus Laravel backend. The backend is mature (287 passing tests). Treat it with care.

---

## The Golden Rule for the Backend

The backend is **not a playground**. It handles real money, real contracts, and real user data. Before changing anything in the backend:

1. Read the existing implementation
2. Understand what tests cover it
3. Make the minimal change needed
4. Run `php artisan test` before claiming the change is done

---

## Architecture Summary

The backend follows strict layered architecture. Each layer has exactly one job.

```
Route → FormRequest → Controller → Service → Model
                  ↓
          Observer / Event / Listener (side effects)
```

| Layer | Lives In | Job |
|-------|----------|-----|
| Validation | `app/Http/Requests/` | Validate input fields. `authorize()` checks ownership. |
| Controller | `app/Http/Controllers/` | Thin. Calls the service. Returns the response. Nothing else. |
| Service | `app/Services/` | All business logic. Transactions. Domain rules. |
| Model | `app/Models/` | Eloquent. Enums. Scopes. Invariants. No business logic. |
| Policy | `app/Policies/` | Per-model authorization checks |
| Observer | `app/Observers/` | Side effects: audit logs, cache invalidation |
| Event/Listener | `app/Events/`, `app/Listeners/` | Async side effects: notifications |

Do not put business logic in a controller. Do not put database queries in a controller. Do not put validation in a service.

---

## Things You Must Not Touch Without Explicit Approval

These are protected by architecture and must not change:

1. **Ledger immutability** — `LedgerEntry` cannot be updated or deleted. `transitionStatus()` is the only allowed state change. Corrections are compensating entries.
2. **Money in cents** — All money is stored as integers in cents. Never change this to floats or decimals.
3. **UUID PKs on contracts and ledger entries** — Never change to auto-increment integers.
4. **Stripe webhook signature verification** — The signature check must never be bypassed.
5. **Payment idempotency** — `stripe_payment_intent_id` uniqueness prevents double-charges.
6. **Audit log append-only** — `audit_logs` has no `updated_at`. New records only, never updates.

---

## Coding Rules

### Controllers are thin
```php
// Good controller action
public function store(CreateListingRequest $request): JsonResponse
{
    $listing = $this->listingService->create(
        $request->validated(),
        $request->user()
    );

    return response()->json(['data' => new ListingResource($listing)], 201);
}

// Bad controller action
public function store(Request $request): JsonResponse
{
    // validation inline — wrong
    $data = $request->validate([...]);

    // business logic in controller — wrong
    if ($request->user()->listings()->count() >= 10) {
        return response()->json(['error' => 'Too many listings'], 422);
    }

    $listing = Listing::create([...]); // query in controller — wrong
    return response()->json($listing);
}
```

### FormRequests handle validation and authorization
```php
class CreateListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership check here
        return $this->user()->user_type === 'landlord'
            && $this->route('property')->landlord_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'rent_amount' => ['required', 'integer', 'min:1'], // cents
        ];
    }
}
```

### Services handle business logic
```php
class ListingService
{
    public function create(array $data, User $landlord): Listing
    {
        return DB::transaction(function () use ($data, $landlord) {
            $listing = Listing::create([
                ...$data,
                'landlord_id' => $landlord->id,
                'status' => ListingStatus::Draft,
            ]);

            event(new ListingCreated($listing));

            return $listing;
        });
    }
}
```

---

## Enums

Domain constants live in `app/Enums/`. Use them. Never hardcode status strings.

```php
// Good
$listing->status === ListingStatus::Active

// Bad
$listing->status === 'active'
```

---

## Testing Rules

Tests live in `tests/Feature/` and `tests/Unit/`. The suite is currently at 287 passing tests.

- Never commit with failing tests
- Add tests for any new behavior you introduce
- Use `RefreshDatabase` trait — tests use SQLite in-memory
- Test negative cases too (unauthorized access, invalid state transitions, IDOR attempts)
- Run: `php artisan test` or `composer test`

---

## Adding New API Endpoints

Follow this checklist:
1. Add the route to the appropriate route file (`routes/api.php`, `api_contracts.php`, or `api_ledger.php`)
2. Apply the correct middleware group (auth + role guard + rate limit)
3. Create a FormRequest for any write operation
4. Write a thin controller method
5. Add business logic to the appropriate service
6. Write at least one feature test
7. Check that Pint passes (`./vendor/bin/pint`)

---

## Rate Limiting

Rate limits are role-aware (`RateLimitByRole` middleware). Do not bypass them. Do not hardcode new limits — add them to the rate limit configuration.

---

## Security Rules (Non-Negotiable)

- All authorization is server-side — the frontend is never the gate
- Use `$fillable` whitelists on every model — no mass assignment of arbitrary data
- Use Eloquent or query bindings only — no raw string interpolation in SQL
- Never commit `.env`, credentials, or Stripe keys
- Validate file uploads at the boundary — never trust file type from the client
- `APP_DEBUG=false` in production — stack traces must not reach API responses

---

## Code Formatting

PHP code must pass Pint before committing:
```bash
./vendor/bin/pint
```

---

## What Not To Do

- Do not add business logic to models beyond Eloquent scopes and simple invariants
- Do not write database queries in controllers or FormRequests
- Do not bypass middleware with ad-hoc auth checks in controllers
- Do not return stack traces or exception messages in production API responses
- Do not write `$model->update($request->all())` — always use validated data explicitly
- Do not store monetary amounts as floats
- Do not create new Eloquent models without adding them to the relevant seeder if they need demo data
