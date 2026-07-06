<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ListingStatus;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * ListingReviewService
 *
 * Assembles everything an admin needs to decide on a listing, computed
 * strictly from real data. There are no invented scores or fabricated
 * signals here: the compliance checklist, warnings, completeness, photo
 * counts and timeline are all derived from the listing, its unit/property,
 * the landlord record, uploaded media, and the append-only audit log.
 *
 * Two product thresholds are the only judgment calls, named here so the
 * policy is explicit and tunable:
 *  - MIN_DESCRIPTION_LENGTH mirrors the SubmitListingRequest gate.
 *  - RECOMMENDED_MIN_PHOTOS is a soft "warn", never a hard block.
 */
class ListingReviewService
{
    /** Below this many characters a description is flagged as thin (matches submit gate). */
    private const MIN_DESCRIPTION_LENGTH = 50;

    /** Fewer photos than this earns a soft warning (not a failure). */
    private const RECOMMENDED_MIN_PHOTOS = 3;

    /** Minimum comparable ACTIVE listings before a price comparison is shown. */
    private const MIN_PRICE_COMPARABLES = 3;

    /** Rent this far above the area median earns a soft "outlier" flag. */
    private const PRICE_OUTLIER_RATIO = 1.25;

    /**
     * Phrases that commonly signal exclusionary / discriminatory tenant
     * preferences. This is a HEURISTIC advisory only — a match never blocks a
     * listing, it asks a human to read the sentence in context (a phrase can be
     * innocent, e.g. "no children's playground on site"). Tuned for the Ghanaian
     * rental market; refine as platform policy evolves.
     */
    private const EXCLUSIONARY_PHRASES = [
        'no children', 'no kids', 'children not allowed',
        'professionals only', 'working professionals only', 'working class only',
        'no students', 'students not allowed',
        'men only', 'women only', 'ladies only', 'gentlemen only', 'females only', 'males only',
        'christians only', 'muslims only', 'no muslims', 'no christians',
        'same tribe', 'no foreigners', 'nationals only', 'ghanaians only',
        'singles only', 'married couples only', 'no couples', 'no single mothers',
    ];

    /** Statuses that are meaningful to a moderator's queue. */
    private const REVIEWABLE_STATUSES = [
        ListingStatus::PENDING_REVIEW->value,
        ListingStatus::ACTIVE->value,
        ListingStatus::REJECTED->value,
    ];

    /**
     * The review queue: truthful per-status counts plus a filtered,
     * sorted, searchable list of listing summaries.
     *
     * @param  array{status?:string,search?:string,sort?:string}  $filters
     * @return array{counts:array<string,int>,data:array<int,array<string,mixed>>}
     */
    public function queue(array $filters = []): array
    {
        $status = $filters['status'] ?? 'pending';
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'newest';

        $query = Listing::query()
            ->with(['unit.property', 'landlord', 'mediaAssets', 'photos']);

        match ($status) {
            'approved' => $query->where('status', ListingStatus::ACTIVE->value),
            'rejected' => $query->where('status', ListingStatus::REJECTED->value),
            'all' => $query->whereIn('status', self::REVIEWABLE_STATUSES),
            default => $query->where('status', ListingStatus::PENDING_REVIEW->value),
        };

        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereHas('landlord', function ($lq) use ($like) {
                        $lq->whereRaw('LOWER(first_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                    })
                    ->orWhereHas('unit.property', function ($pq) use ($like) {
                        $pq->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(city) LIKE ?', [$like]);
                    });
            });
        }

        match ($sort) {
            'oldest' => $query->orderBy('listings.created_at', 'asc'),
            'rent_high' => $query->leftJoin('units', 'listings.unit_id', '=', 'units.id')
                ->orderByDesc('units.rent_amount')->select('listings.*'),
            'rent_low' => $query->leftJoin('units', 'listings.unit_id', '=', 'units.id')
                ->orderBy('units.rent_amount', 'asc')->select('listings.*'),
            default => $query->orderBy('listings.created_at', 'desc'),
        };

        // Admin moderation queues are small; a generous cap keeps the response
        // bounded without paginating a list the SPA scans in one view.
        $listings = $query->limit(200)->get();

        $data = $listings->map(fn (Listing $l) => $this->summary($l));

        // "Needs attention first" can only be ordered after signals are computed.
        if ($sort === 'attention') {
            $data = $data->sortByDesc(fn ($row) => $row['warning_count'])->values();
        }

        return [
            'counts' => $this->counts(),
            'data' => $data->values()->all(),
        ];
    }

    /**
     * Truthful summary counts for the queue's header cards.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $pending = Listing::query()
            ->where('status', ListingStatus::PENDING_REVIEW->value)
            ->with(['unit.property', 'landlord', 'mediaAssets', 'photos'])
            ->get();

        $needsAttention = 0;
        $missingInfo = 0;
        foreach ($pending as $listing) {
            $signals = $this->signals($listing);
            if ($signals['warning_count'] > 0) {
                $needsAttention++;
            }
            if ($signals['missing_count'] > 0) {
                $missingInfo++;
            }
        }

        return [
            'pending' => $pending->count(),
            'approved' => Listing::where('status', ListingStatus::ACTIVE->value)->count(),
            'rejected' => Listing::where('status', ListingStatus::REJECTED->value)->count(),
            'all' => Listing::whereIn('status', self::REVIEWABLE_STATUSES)->count(),
            'approved_today' => Listing::where('status', ListingStatus::ACTIVE->value)
                ->whereDate('published_at', now()->toDateString())->count(),
            'needs_attention' => $needsAttention,
            'missing_info' => $missingInfo,
        ];
    }

    /**
     * Full review detail payload for a single listing.
     *
     * @return array<string,mixed>
     */
    public function detail(Listing $listing): array
    {
        $listing->loadMissing([
            'unit.property',
            'landlord.latestVerificationRequest',
            'mediaAssets',
            'photos',
            'reviewer',
            'notes.admin',
        ]);

        $signals = $this->signals($listing);
        $unit = $listing->unit;
        $property = $unit?->property;

        return [
            'id' => $listing->id,
            'title' => $listing->title,
            'description' => $listing->description,
            'status' => $listing->status->value,
            'status_label' => $listing->status->label(),
            'rejection_reason' => $listing->rejection_reason,
            'changes_requested_reason' => $listing->changes_requested_reason,
            'changes_requested_at' => $this->iso($listing->changes_requested_at),
            'featured' => (bool) $listing->featured,
            'view_count' => (int) $listing->view_count,
            'pets_allowed' => (bool) $listing->pets_allowed,
            'pet_policy' => $listing->pet_policy,
            'lease_duration_months' => $listing->lease_duration_months,
            'move_in_date' => $this->iso($listing->move_in_date),
            'published_at' => $this->iso($listing->published_at),
            'expires_at' => $this->iso($listing->expires_at),
            'reviewed_at' => $this->iso($listing->reviewed_at),
            'created_at' => $this->iso($listing->created_at),
            'updated_at' => $this->iso($listing->updated_at),

            'unit' => $unit ? [
                'id' => $unit->id,
                'unit_number' => $unit->unit_number,
                'internal_name' => $unit->internal_name,
                'bedrooms' => $unit->bedrooms,
                'bathrooms' => $unit->bathrooms,
                'square_feet' => $unit->square_feet,
                'rent_amount' => $unit->rent_amount,
                'security_deposit' => $unit->security_deposit,
                'availability_status' => $this->enumValue($unit->availability_status),
                'availability_label' => $unit->availability_status?->label(),
                'available_from' => $this->iso($unit->available_from),
                'amenities' => $unit->amenities ?? [],
                'is_active' => (bool) $unit->is_active,
            ] : null,

            'property' => $property ? [
                'id' => $property->id,
                'name' => $property->name,
                'property_type' => $this->enumValue($property->property_type),
                'full_address' => $property->full_address,
                'street_address' => $property->street_address,
                'city' => $property->city,
                'state' => $property->state,
                'zip_code' => $property->zip_code,
                'country' => $property->country,
                'year_built' => $property->year_built,
                'description' => $property->description,
                'is_active' => (bool) $property->is_active,
            ] : null,

            'landlord' => $this->landlordBlock($listing->landlord, $listing),
            'photos' => $this->photos($listing),
            'photo_count' => $signals['photo_count'],
            'verification' => $this->verificationBlock($listing),
            'checklist' => $signals['checklist'],
            'warnings' => $signals['warnings'],
            'content_flags' => $signals['content_flags'],
            'completeness' => $signals['completeness'],
            'ready_for_approval' => $signals['ready_for_approval'],
            'pricing' => $this->pricing($listing),
            'address_visibility' => $this->addressVisibility($property),
            'timeline' => $this->timeline($listing),
            'notes' => $this->notes($listing),
            'reviewer' => $listing->reviewer ? [
                'id' => $listing->reviewer->id,
                'name' => $listing->reviewer->name,
            ] : null,
            'reviewable' => $listing->status === ListingStatus::PENDING_REVIEW,
        ];
    }

    /**
     * Tenant-safe preview payload: exactly what a tenant would see once the
     * listing is public. Excludes every admin-only field (notes, warnings,
     * checklist, landlord PII, verification internals).
     *
     * @return array<string,mixed>
     */
    public function preview(Listing $listing): array
    {
        $listing->loadMissing(['unit.property', 'landlord', 'mediaAssets', 'photos']);
        $unit = $listing->unit;
        $property = $unit?->property;
        $landlord = $listing->landlord;

        return [
            'id' => $listing->id,
            'title' => $listing->title,
            'description' => $listing->description,
            'status' => $listing->status->value,
            'pets_allowed' => (bool) $listing->pets_allowed,
            'pet_policy' => $listing->pet_policy,
            'lease_duration_months' => $listing->lease_duration_months,
            'move_in_date' => $this->iso($listing->move_in_date),
            'photos' => $this->photos($listing),
            'photo_count' => $this->photoCount($listing),
            'unit' => $unit ? [
                'unit_number' => $unit->unit_number,
                'bedrooms' => $unit->bedrooms,
                'bathrooms' => $unit->bathrooms,
                'square_feet' => $unit->square_feet,
                'rent_amount' => $unit->rent_amount,
                'security_deposit' => $unit->security_deposit,
                'available_from' => $this->iso($unit->available_from),
                'amenities' => $unit->amenities ?? [],
            ] : null,
            'property' => $property ? [
                'name' => $property->name,
                'property_type' => $this->enumValue($property->property_type),
                ...$property->publicAddress(),
            ] : null,
            // Tenant-facing trust signal only: a verified landlord, never PII.
            'landlord' => $landlord ? [
                'name' => $landlord->full_name,
                'identity_verified' => (bool) $landlord->identity_verified,
            ] : null,
        ];
    }

    /**
     * The compliance signals used everywhere. Single source of truth so the
     * queue and detail views can never disagree.
     *
     * @return array{checklist:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>,photo_count:int,completeness:array<string,int>,missing_count:int,warning_count:int,ready_for_approval:bool}
     */
    public function signals(Listing $listing): array
    {
        $unit = $listing->unit;
        $property = $unit?->property;
        $landlord = $listing->landlord;

        $photoCount = $this->photoCount($listing);
        $checklist = [];
        $add = function (string $key, string $label, string $status, ?string $detail = null) use (&$checklist): void {
            $checklist[] = compact('key', 'label', 'status', 'detail');
        };

        $hasTitle = filled($listing->title);
        $add('title', 'Listing title provided', $hasTitle ? 'pass' : 'fail', $hasTitle ? null : 'No title set.');

        $descLen = mb_strlen(trim((string) $listing->description));
        if ($descLen === 0) {
            $add('description', 'Description provided', 'fail', 'No description written.');
        } elseif ($descLen < self::MIN_DESCRIPTION_LENGTH) {
            $add('description', 'Description provided', 'warn', "Only {$descLen} characters — thin for tenants.");
        } else {
            $add('description', 'Description provided', 'pass');
        }

        $rent = $unit ? (float) $unit->rent_amount : 0.0;
        $add('rent', 'Rent amount set', $rent > 0 ? 'pass' : 'fail', $rent > 0 ? null : 'No rent set on the unit.');

        $add('unit', 'Unit assigned', $unit ? 'pass' : 'fail', $unit ? "Unit {$unit->unit_number}." : 'No unit linked.');

        $addressOk = $property && filled($property->street_address) && filled($property->city) && filled($property->state);
        $add('address', 'Address complete', $addressOk ? 'pass' : 'fail', $addressOk ? null : 'Street, city or region missing.');

        if ($photoCount === 0) {
            $add('photos', 'At least one photo', 'fail', 'No photos uploaded.');
        } elseif ($photoCount < self::RECOMMENDED_MIN_PHOTOS) {
            $add('photos', 'Photos uploaded', 'warn', "Only {$photoCount} photo(s) — fewer than recommended.");
        } else {
            $add('photos', 'Photos uploaded', 'pass', "{$photoCount} photos.");
        }

        $accountActive = $landlord
            && $landlord->account_status === AccountStatus::ACTIVE
            && $landlord->is_active;
        $add('landlord_active', 'Landlord account active', $accountActive ? 'pass' : 'fail', $accountActive ? null : 'Landlord account is not active.');

        $identityVerified = (bool) $landlord?->identity_verified;
        $add('landlord_verified', 'Landlord identity verified', $identityVerified ? 'pass' : 'warn', $identityVerified ? null : 'Landlord has not passed identity verification.');

        $duplicate = $this->hasDuplicateActiveListing($listing);
        $add('duplicate', 'No duplicate active listing', $duplicate ? 'fail' : 'pass', $duplicate ? 'Another active/pending listing exists for this unit.' : null);

        $bedBathOk = $unit && $unit->bedrooms !== null && $unit->bathrooms !== null;
        $add('unit_details', 'Bedrooms & bathrooms set', $bedBathOk ? 'pass' : 'warn', $bedBathOk ? null : 'Unit is missing bed/bath counts.');

        // Contact details in the description: a real, deterministic policy check
        // (tenants must book through the platform, not a private number/email).
        $pii = $this->detectPii($listing->description);
        $add('no_contact_info', 'No contact details in description', $pii ? 'warn' : 'pass',
            $pii ? 'Found '.implode(', ', $pii).' in the description — tenants should book through the platform.' : null);

        // Advisory heuristic: possible exclusionary language. A match warns, never
        // blocks; the admin reads the phrase in context and decides.
        $policyPhrases = $this->detectExclusionaryPhrases($listing->description);
        $add('no_exclusionary_language', 'No exclusionary language', $policyPhrases ? 'warn' : 'pass',
            $policyPhrases ? 'Possible exclusionary wording: "'.implode('", "', $policyPhrases).'" — please review in context.' : null);

        // Warnings are a derived view of the checklist's non-passing items,
        // plus the historical "previously rejected" signal.
        $warnings = [];
        foreach ($checklist as $item) {
            if ($item['status'] === 'fail') {
                $warnings[] = ['key' => $item['key'], 'label' => $item['detail'] ?? $item['label'], 'severity' => 'high'];
            } elseif ($item['status'] === 'warn') {
                $warnings[] = ['key' => $item['key'], 'label' => $item['detail'] ?? $item['label'], 'severity' => 'medium'];
            }
        }
        if (filled($listing->rejection_reason)) {
            $warnings[] = ['key' => 'previously_rejected', 'label' => 'This listing was previously rejected.', 'severity' => 'medium'];
        }

        $total = count($checklist);
        $passed = count(array_filter($checklist, fn ($i) => $i['status'] === 'pass'));
        $missing = count(array_filter($checklist, fn ($i) => $i['status'] === 'fail'));

        return [
            'checklist' => $checklist,
            'warnings' => $warnings,
            'photo_count' => $photoCount,
            'completeness' => [
                'passed' => $passed,
                'total' => $total,
                'percent' => $total > 0 ? (int) round($passed / $total * 100) : 0,
            ],
            'missing_count' => $missing,
            'warning_count' => count($warnings),
            // Raw matched spans so the UI can highlight them in the description.
            'content_flags' => [
                'pii' => $pii,
                'policy_phrases' => $policyPhrases,
            ],
            // A listing is safe to approve when nothing is outright failing.
            'ready_for_approval' => $missing === 0,
        ];
    }

    /**
     * Compact per-row summary for the queue.
     *
     * @return array<string,mixed>
     */
    private function summary(Listing $listing): array
    {
        $signals = $this->signals($listing);
        $unit = $listing->unit;
        $property = $unit?->property;
        $landlord = $listing->landlord;

        // Boolean signal flags for the queue's filter chips, derived from the
        // checklist we already computed (no extra queries). Each maps to a real
        // failing/warning check — never an invented score.
        $byKey = [];
        foreach ($signals['checklist'] as $item) {
            $byKey[$item['key']] = $item['status'];
        }
        $flags = [
            'few_photos' => ($byKey['photos'] ?? 'pass') !== 'pass',
            'duplicate' => ($byKey['duplicate'] ?? 'pass') === 'fail',
            'unverified_host' => ($byKey['landlord_verified'] ?? 'pass') !== 'pass',
            'contact_info' => ($byKey['no_contact_info'] ?? 'pass') !== 'pass',
            'policy' => ($byKey['no_exclusionary_language'] ?? 'pass') !== 'pass',
        ];

        $photos = $this->photos($listing);

        return [
            'id' => $listing->id,
            'title' => $listing->title,
            'status' => $listing->status->value,
            'status_label' => $listing->status->label(),
            'submitted_at' => $this->iso($listing->created_at),
            'reviewed_at' => $this->iso($listing->reviewed_at),
            'landlord' => [
                'id' => $landlord?->id,
                'name' => $landlord?->full_name ?? ('Landlord #'.$listing->landlord_id),
                'identity_verified' => (bool) $landlord?->identity_verified,
                'verification_status' => $this->enumValue($landlord?->verification_status),
            ],
            'unit' => $unit ? [
                'unit_number' => $unit->unit_number,
                'bedrooms' => $unit->bedrooms,
                'bathrooms' => $unit->bathrooms,
                'rent_amount' => $unit->rent_amount,
            ] : null,
            'property_name' => $property?->name,
            'location' => $property ? trim(trim(($property->city ?? '').', '.($property->state ?? '')), ', ') : null,
            'cover_photo' => $photos[0]['url'] ?? null,
            'photo_count' => $signals['photo_count'],
            'warning_count' => $signals['warning_count'],
            'missing_count' => $signals['missing_count'],
            'completeness' => $signals['completeness'],
            'flags' => $flags,
            'rejection_reason' => $listing->rejection_reason,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function landlordBlock(?User $landlord, Listing $listing): ?array
    {
        if (! $landlord) {
            return null;
        }

        return [
            'id' => $landlord->id,
            'name' => $landlord->full_name,
            'email' => $landlord->email,
            'phone' => $landlord->phone,
            'avatar_url' => $landlord->avatar_url,
            'account_status' => $this->enumValue($landlord->account_status),
            'verification_status' => $this->enumValue($landlord->verification_status),
            'identity_verified' => (bool) $landlord->identity_verified,
            'created_at' => $this->iso($landlord->created_at),
            'active_listings' => Listing::where('landlord_id', $landlord->id)
                ->where('status', ListingStatus::ACTIVE->value)->count(),
            'rejected_listings' => Listing::where('landlord_id', $landlord->id)
                ->where('status', ListingStatus::REJECTED->value)->count(),
            'total_listings' => Listing::where('landlord_id', $landlord->id)->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function verificationBlock(Listing $listing): array
    {
        $unit = $listing->unit;
        $property = $unit?->property;

        return [
            'property_active' => (bool) $property?->is_active,
            'unit_active' => (bool) $unit?->is_active,
            'unit_availability' => $this->enumValue($unit?->availability_status),
            'unit_availability_label' => $unit?->availability_status?->label(),
            'unit_can_be_listed' => (bool) ($unit?->canBeListed() ?? false),
            'duplicate_active_listing' => $this->hasDuplicateActiveListing($listing),
            'landlord_identity_verified' => (bool) $listing->landlord?->identity_verified,
        ];
    }

    /**
     * How much of the address a tenant will see, and the real rule behind it.
     * Mirrors Property::publicAddress(): the street is hidden unless the landlord
     * set the property's address_visibility to "public".
     *
     * @return array<string,mixed>
     */
    private function addressVisibility(mixed $property): array
    {
        $streetPublic = $property?->address_visibility === 'public';

        return [
            'admin_full_address' => $property?->full_address,
            'street_address' => $property?->street_address,
            'tenant_area' => $property ? trim(trim(($property->city ?? '').', '.($property->state ?? '')), ', ') : null,
            'street_public' => $streetPublic,
            'rule' => $streetPublic
                ? 'Landlord has made the full street address public.'
                : 'Tenants see the area only; the street address stays hidden until an application is approved.',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function photos(Listing $listing): array
    {
        $media = $listing->relationLoaded('mediaAssets') ? $listing->mediaAssets : $listing->mediaAssets()->get();

        if ($media->isNotEmpty()) {
            return $media->map(fn ($asset) => [
                'id' => $asset->id,
                'url' => $asset->url,
                'alt_text' => $asset->alt_text,
                'caption' => $asset->caption,
                'is_primary' => false,
                'created_at' => $this->iso($asset->created_at),
            ])->values()->all();
        }

        // Legacy ListingPhoto fallback: derive a URL from disk+path directly.
        $photos = $listing->relationLoaded('photos') ? $listing->photos : $listing->photos()->get();

        return $photos->map(fn ($photo) => [
            'id' => $photo->id,
            'url' => $photo->path
                ? Storage::disk($photo->disk ?: config('filesystems.default'))->url($photo->path)
                : null,
            'alt_text' => $photo->alt_text,
            'caption' => null,
            'is_primary' => (bool) $photo->is_primary,
            'created_at' => $this->iso($photo->created_at),
        ])->values()->all();
    }

    private function photoCount(Listing $listing): int
    {
        $media = $listing->relationLoaded('mediaAssets')
            ? $listing->mediaAssets->count()
            : $listing->mediaAssets()->count();

        $legacy = $listing->relationLoaded('photos')
            ? $listing->photos->count()
            : $listing->photos()->count();

        return $media + $legacy;
    }

    private function hasDuplicateActiveListing(Listing $listing): bool
    {
        if (! $listing->unit_id) {
            return false;
        }

        return Listing::query()
            ->where('unit_id', $listing->unit_id)
            ->where('id', '!=', $listing->id)
            ->whereIn('status', [ListingStatus::ACTIVE->value, ListingStatus::PENDING_REVIEW->value])
            ->exists();
    }

    /**
     * Detect phone numbers / email addresses embedded in free text. Returns the
     * matched spans exactly as written so the UI can highlight them.
     *
     * @return array<int,string>
     */
    private function detectPii(?string $text): array
    {
        $text = (string) $text;
        $matches = [];

        // Emails.
        if (preg_match_all('/[\w.+-]+@[\w-]+\.[\w.-]+/', $text, $m)) {
            $matches = array_merge($matches, $m[0]);
        }
        // Ghanaian / international mobile numbers, e.g. "024 555 0130" or "+233 24 555 0130".
        if (preg_match_all('/(?:\+233|0)\s?\d{2}[\s-]?\d{3}[\s-]?\d{4}/', $text, $m)) {
            $matches = array_merge($matches, $m[0]);
        }

        return array_values(array_unique(array_filter(array_map('trim', $matches))));
    }

    /**
     * Case-insensitive scan for exclusionary phrases. Returns each phrase as it
     * appears in the text (preserving case) so the UI can highlight it verbatim.
     *
     * @return array<int,string>
     */
    private function detectExclusionaryPhrases(?string $text): array
    {
        $text = (string) $text;
        $found = [];
        foreach (self::EXCLUSIONARY_PHRASES as $phrase) {
            if (preg_match('/'.preg_quote($phrase, '/').'/i', $text, $m)) {
                $found[] = $m[0];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Real, guarded price context. Compares the unit's rent to the median rent of
     * currently ACTIVE listings for the same property type in the same city. When
     * there are fewer than MIN_PRICE_COMPARABLES comparables we return
     * has_comparison=false rather than inventing a median from a handful of rows.
     *
     * @return array<string,mixed>
     */
    public function pricing(Listing $listing): array
    {
        $unit = $listing->unit;
        $property = $unit?->property;
        $rent = $unit ? (float) $unit->rent_amount : 0.0;
        $deposit = $unit && $unit->security_deposit !== null ? (float) $unit->security_deposit : null;

        $base = [
            'rent' => $rent > 0 ? $rent : null,
            'deposit' => $deposit,
            'deposit_months' => ($deposit !== null && $rent > 0) ? round($deposit / $rent, 1) : null,
            'area' => $property ? trim(trim(($property->city ?? '').', '.($property->state ?? '')), ', ') : null,
            'has_comparison' => false,
            'median' => null,
            'comparable_count' => 0,
            'percent_diff' => null,
            'is_outlier' => false,
        ];

        if (! $unit || ! $property || $rent <= 0 || ! filled($property->city)) {
            return $base;
        }

        $rents = Unit::query()
            ->join('listings', 'listings.unit_id', '=', 'units.id')
            ->join('properties', 'units.property_id', '=', 'properties.id')
            ->where('listings.status', ListingStatus::ACTIVE->value)
            ->where('properties.city', $property->city)
            ->when($property->property_type, fn ($q) => $q->where('properties.property_type', $this->enumValue($property->property_type)))
            ->where('units.id', '!=', $unit->id)
            ->pluck('units.rent_amount')
            ->map(fn ($v) => (float) $v)
            ->filter(fn ($v) => $v > 0)
            ->values();

        if ($rents->count() < self::MIN_PRICE_COMPARABLES) {
            return array_merge($base, ['comparable_count' => $rents->count()]);
        }

        $median = $this->median($rents->all());
        $percentDiff = $median > 0 ? (int) round(($rent / $median - 1) * 100) : null;

        return array_merge($base, [
            'has_comparison' => true,
            'median' => $median,
            'comparable_count' => $rents->count(),
            'percent_diff' => $percentDiff,
            'is_outlier' => $rent > $median * self::PRICE_OUTLIER_RATIO,
        ]);
    }

    /**
     * @param  array<int,float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : (($values[$mid - 1] + $values[$mid]) / 2);
    }

    /**
     * Real lifecycle timeline: the draft-created moment plus every audited
     * moderation event. Never fabricated — if the audit log has no entry,
     * the event does not appear.
     *
     * @return array<int,array<string,mixed>>
     */
    private function timeline(Listing $listing): array
    {
        $events = [[
            'key' => 'created',
            'label' => 'Draft created',
            'at' => $this->iso($listing->created_at),
            'actor' => $listing->landlord?->full_name,
            'detail' => null,
            'severity' => 'info',
        ]];

        $labels = [
            'listing_submitted' => ['Submitted for review', 'info'],
            'listing_published' => ['Approved & published', 'success'],
            'listing_rejected' => ['Rejected', 'danger'],
            'listing_changes_requested' => ['Changes requested', 'warning'],
        ];

        $logs = AuditLog::query()
            ->where('subject_type', Listing::class)
            ->where('subject_id', $listing->id)
            ->orderBy('created_at')
            ->get();

        foreach ($logs as $log) {
            if (! isset($labels[$log->action])) {
                continue;
            }
            [$label, $severity] = $labels[$log->action];
            $events[] = [
                'key' => $log->action,
                'label' => $label,
                'at' => $this->iso($log->created_at),
                'actor' => $this->actorName($log),
                'detail' => $log->metadata['reason'] ?? null,
                'severity' => $severity,
            ];
        }

        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $events;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function notes(Listing $listing): array
    {
        return $listing->notes->map(fn ($note) => [
            'id' => $note->id,
            'body' => $note->body,
            'admin_id' => $note->admin_id,
            'admin_name' => $note->admin?->name,
            'created_at' => $this->iso($note->created_at),
        ])->values()->all();
    }

    private function actorName(AuditLog $log): ?string
    {
        if (! $log->actor_type || ! $log->actor_id) {
            return 'System';
        }

        /** @var \Illuminate\Database\Eloquent\Model|null $actor */
        $actor = $log->actor_type::query()->find($log->actor_id);
        if (! $actor) {
            return null;
        }

        return $actor->full_name ?? $actor->name ?? $actor->email ?? null;
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function iso($value): ?string
    {
        return $value instanceof \Carbon\CarbonInterface ? $value->toIso8601String() : ($value ?: null);
    }
}
