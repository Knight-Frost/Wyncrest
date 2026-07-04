<?php

namespace Database\Seeders\Dev;

/**
 * SeedCatalog
 *
 * The SINGLE deterministic source of truth for the Wyncrest development world.
 *
 * This is intentionally SMALL and readable — a controlled testing environment, not
 * a showroom. Everything here is fictional and Ghana-focused, and every value is
 * FIXED (no random generation) so repeated `migrate:fresh --seed` runs produce a
 * stable graph that the developer can recognise account-by-account.
 *
 * The world it describes:
 *   - 4 admins (seeded in UserSeeder): 1 super, 2 scoped (content + finance),
 *     1 pending invite
 *   - 7 landlords (4 operating + 1 empty-state + 1 pending-verification +
 *     1 suspended)
 *   - 9 tenants   (4 good standing + 1 owing one month + 1 owing rent & a late
 *     fee + 2 former tenants [1 terminated, 1 expired] + 1 unverified)
 *   - 4 properties / 13 units, with listings spanning active / pending-review /
 *     draft / inactive so browse, the moderation queue and occupied units are all
 *     testable. Contracts span active / terminated / expired.
 *
 * Money is expressed in GH₵ major units here and converted to integer cents where
 * the schema stores cents (contracts/ledger). Demo users are referenced everywhere
 * by their email LOCAL PART (the "key"), e.g. 'tenant.owing'. Seeders resolve a key
 * to a User via email = "{key}@{config('seed.development.email_domain')}".
 */
class SeedCatalog
{
    /**
     * Landlord demo accounts (5). `key` is the email local part.
     *
     * verification: 'verified' (every operating landlord is verified so their
     *               active listings/leases are a legal product state)
     * account:      'active'
     * features:     'full' | 'limited'  (see FEATURE_TIERS)
     * purpose:      human-readable role, printed in the seed summary + report.
     *
     * landlord.empty is an INTENTIONAL empty-state account (verified, full
     * features, but zero properties) so empty landlord dashboards can be tested.
     * It is named obviously so it is never mistaken for missing data.
     */
    public const LANDLORDS = [
        ['key' => 'landlord.1', 'first' => 'Kwame', 'last' => 'Mensah', 'city' => 'Accra', 'verification' => 'verified', 'account' => 'active', 'features' => 'full', 'purpose' => 'Established landlord — 1 property, active + former tenants, an available listing'],
        ['key' => 'landlord.2', 'first' => 'Ama', 'last' => 'Owusu', 'city' => 'Accra', 'verification' => 'verified', 'account' => 'active', 'features' => 'full', 'purpose' => 'Landlord of the owing + late-fee tenants — 1 property, active tenants, a listing in review'],
        ['key' => 'landlord.3', 'first' => 'Kofi', 'last' => 'Asante', 'city' => 'Kumasi', 'verification' => 'verified', 'account' => 'active', 'features' => 'full', 'purpose' => 'Smaller landlord — 1 property, 1 active tenant + 1 terminated lease, an available listing'],
        ['key' => 'landlord.4', 'first' => 'Akosua', 'last' => 'Boateng', 'city' => 'Tema', 'verification' => 'verified', 'account' => 'active', 'features' => 'limited', 'purpose' => 'Listings-only landlord (limited features) — listings but no tenants yet'],
        ['key' => 'landlord.empty', 'first' => 'Yaw', 'last' => 'Darko', 'city' => 'Takoradi', 'verification' => 'verified', 'account' => 'active', 'features' => 'full', 'purpose' => 'EMPTY-STATE account — verified, full features, but no properties (tests empty dashboards)'],

        // --- Edge-state landlords (no properties, so they never ripple into the
        //     property/listing/contract/ledger graph) --------------------------
        ['key' => 'landlord.pending', 'first' => 'Kojo', 'last' => 'Ansah', 'city' => 'Accra', 'verification' => 'pending', 'account' => 'active', 'features' => 'none', 'purpose' => 'PENDING VERIFICATION — no features, blocked from submitting a listing (tests the identity hard-gate + admin verification queue)'],
        ['key' => 'landlord.suspended', 'first' => 'Abena', 'last' => 'Quaye', 'city' => 'Accra', 'verification' => 'verified', 'account' => 'suspended', 'features' => 'none', 'purpose' => 'SUSPENDED ACCOUNT — verified but suspended, login is rejected (tests account governance + admin reactivate)'],
    ];

    /**
     * Tenant demo accounts (5). Four are in good standing (active lease, fully paid
     * ledger, zero balance); one owes exactly one month of rent.
     *
     * standing: 'good' | 'owing'  — printed in the seed summary + report.
     */
    public const TENANTS = [
        ['key' => 'tenant.good1', 'first' => 'Efua', 'last' => 'Addo', 'city' => 'Accra', 'verification' => 'verified', 'account' => 'active', 'standing' => 'good'],
        ['key' => 'tenant.good2', 'first' => 'Nana', 'last' => 'Yeboah', 'city' => 'Accra', 'verification' => 'verified', 'account' => 'active', 'standing' => 'good'],
        ['key' => 'tenant.good3', 'first' => 'Kwesi', 'last' => 'Mensa', 'city' => 'Accra', 'verification' => 'verified', 'account' => 'active', 'standing' => 'good'],
        ['key' => 'tenant.good4', 'first' => 'Adjoa', 'last' => 'Frimpong', 'city' => 'Kumasi', 'verification' => 'verified', 'account' => 'active', 'standing' => 'good'],
        ['key' => 'tenant.owing', 'first' => 'Selorm', 'last' => 'Agbeko', 'city' => 'Accra', 'verification' => 'verified', 'account' => 'active', 'standing' => 'owing'],

        // --- Edge-state tenants -------------------------------------------------
        // latefee : active lease, one overdue month + a REAL late fee (seeded via
        //           LedgerService::generateLateFee) — owes rent + late fee.
        ['key' => 'tenant.latefee', 'first' => 'Yaw', 'last' => 'Owusu-Ansah', 'city' => 'Accra', 'verification' => 'verified', 'account' => 'active', 'standing' => 'latefee'],
        // former  : a TERMINATED lease, paid to zero before leaving — tests the
        //           former-tenant view + a review from a past tenant.
        ['key' => 'tenant.former', 'first' => 'Akua', 'last' => 'Nyarko', 'city' => 'Kumasi', 'verification' => 'verified', 'account' => 'active', 'standing' => 'former'],
        // expired : an EXPIRED lease (ran its full term), paid to zero — tests the
        //           expired-contract lifecycle end.
        ['key' => 'tenant.expired', 'first' => 'Kojo', 'last' => 'Addai', 'city' => 'Accra', 'verification' => 'verified', 'account' => 'active', 'standing' => 'former'],
        // unverified : never verified, no lease — tests the application hard-gate
        //              and tenant empty states (no lease / no applications).
        ['key' => 'tenant.unverified', 'first' => 'Esi', 'last' => 'Bonsu', 'city' => 'Accra', 'verification' => 'unverified', 'account' => 'active', 'standing' => null],
    ];

    /**
     * Properties (4). landlord.empty intentionally owns none. `landlord` is a
     * LANDLORDS key; `state` uses Ghana 2-char region codes (schema column is
     * char(2)); `country` is 'GH'.
     */
    public const PROPERTIES = [
        ['key' => 'ridge-court', 'landlord' => 'landlord.1', 'name' => 'Ridge Court', 'type' => 'apartment', 'street' => '14 Independence Avenue', 'city' => 'Cantonments', 'state' => 'GA', 'zip' => 'GA-100', 'year' => 2017, 'desc' => 'Modern apartments in the diplomatic heart of Cantonments, Accra.'],
        ['key' => 'harbour-view', 'landlord' => 'landlord.2', 'name' => 'Harbour View Residences', 'type' => 'apartment', 'street' => '21 Oxford Street', 'city' => 'Osu', 'state' => 'GA', 'zip' => 'GA-145', 'year' => 2019, 'desc' => 'Secure apartments minutes from Oxford Street and Labadi beach, Osu.'],
        ['key' => 'garden-villas', 'landlord' => 'landlord.3', 'name' => 'Garden Villas', 'type' => 'townhouse', 'street' => '4 Ridge Road', 'city' => 'Kumasi', 'state' => 'AH', 'zip' => 'AH-210', 'year' => 2018, 'desc' => 'Contemporary townhouses on the cool Kumasi ridge.'],
        ['key' => 'tema-residences', 'landlord' => 'landlord.4', 'name' => 'Tema Residences', 'type' => 'condo', 'street' => 'Site 25, Tema', 'city' => 'Tema', 'state' => 'GA', 'zip' => 'GA-620', 'year' => 2021, 'desc' => 'Affordable starter condominiums in Tema Community 25.'],
    ];

    /**
     * 10 rentable units. `property` is a PROPERTIES key. `rent`/`deposit` are GH₵
     * major units.
     *
     * listing:      draft | pending_review | active | inactive
     *               (occupied units carry an INACTIVE listing — taken off-market;
     *                available units carry active/pending_review/draft; a unit whose
     *                lease ended is re-listed active while it looks for a new tenant)
     * availability: available | occupied | unlisted
     * contract:     null | active | terminated | expired
     * tenant:       TENANTS key (when contract is set)
     * months:       lease age/length in months — how much paid history to build
     * standing:     'good' | 'owing' | 'latefee' | 'former' (meaningful when leased)
     *               good=paid to zero, owing=one overdue month, latefee=one overdue
     *               month + a real late fee, former=fully paid then the lease ended
     */
    public const UNITS = [
        // --- Ridge Court (landlord.1): two occupied + one available -------------
        ['type' => 'Two-bedroom apartment', 'property' => 'ridge-court', 'number' => '2B-04', 'bedrooms' => 2, 'bathrooms' => 2, 'sqft' => 1100, 'rent' => 2800, 'deposit' => 5600, 'amenities' => ['Air conditioning', 'Parking', 'Balcony'], 'listing' => 'inactive', 'availability' => 'occupied', 'contract' => 'active', 'tenant' => 'tenant.good1', 'months' => 6, 'standing' => 'good'],
        ['type' => 'Three-bedroom apartment', 'property' => 'ridge-court', 'number' => '3B-02', 'bedrooms' => 3, 'bathrooms' => 2, 'sqft' => 1500, 'rent' => 3200, 'deposit' => 6400, 'amenities' => ['Air conditioning', 'Parking', 'Standby generator', 'Security'], 'listing' => 'inactive', 'availability' => 'occupied', 'contract' => 'active', 'tenant' => 'tenant.good2', 'months' => 4, 'standing' => 'good'],
        ['type' => 'One-bedroom apartment', 'property' => 'ridge-court', 'number' => '1B-07', 'bedrooms' => 1, 'bathrooms' => 1, 'sqft' => 650, 'rent' => 3000, 'deposit' => 6000, 'amenities' => ['Air conditioning', 'Fibre internet', 'Security'], 'listing' => 'active', 'availability' => 'available', 'contract' => null, 'tenant' => null, 'months' => 0, 'standing' => null],

        // --- Harbour View (landlord.2): two occupied (incl. owing) + one in review
        ['type' => 'Studio apartment', 'property' => 'harbour-view', 'number' => 'ST-01', 'bedrooms' => 0, 'bathrooms' => 1, 'sqft' => 420, 'rent' => 4500, 'deposit' => 9000, 'amenities' => ['Air conditioning', 'Sea view', 'Security'], 'listing' => 'inactive', 'availability' => 'occupied', 'contract' => 'active', 'tenant' => 'tenant.good3', 'months' => 9, 'standing' => 'good'],
        ['type' => 'Garden apartment', 'property' => 'harbour-view', 'number' => 'GA-05', 'bedrooms' => 2, 'bathrooms' => 1, 'sqft' => 900, 'rent' => 2500, 'deposit' => 5000, 'amenities' => ['Private garden', 'Parking', 'Pet friendly'], 'listing' => 'inactive', 'availability' => 'occupied', 'contract' => 'active', 'tenant' => 'tenant.owing', 'months' => 2, 'standing' => 'owing'],
        ['type' => 'Loft', 'property' => 'harbour-view', 'number' => 'LFT-2', 'bedrooms' => 1, 'bathrooms' => 1, 'sqft' => 950, 'rent' => 5000, 'deposit' => 10000, 'amenities' => ['High ceilings', 'Open plan', 'Sea view'], 'listing' => 'pending_review', 'availability' => 'available', 'contract' => null, 'tenant' => null, 'months' => 0, 'standing' => null],

        // --- Garden Villas (landlord.3): one occupied + one available ----------
        ['type' => 'Townhouse', 'property' => 'garden-villas', 'number' => 'TH-A', 'bedrooms' => 3, 'bathrooms' => 3, 'sqft' => 1900, 'rent' => 6000, 'deposit' => 12000, 'amenities' => ['Garage', 'Private garden', 'Washer/Dryer', 'Security'], 'listing' => 'inactive', 'availability' => 'occupied', 'contract' => 'active', 'tenant' => 'tenant.good4', 'months' => 3, 'standing' => 'good'],
        ['type' => 'Duplex unit', 'property' => 'garden-villas', 'number' => 'DX-B', 'bedrooms' => 4, 'bathrooms' => 3, 'sqft' => 2200, 'rent' => 6500, 'deposit' => 13000, 'amenities' => ['Garage', 'Balcony', 'Study', 'Security'], 'listing' => 'active', 'availability' => 'available', 'contract' => null, 'tenant' => null, 'months' => 0, 'standing' => null],

        // --- Tema Residences (landlord.4, limited features): listings only -----
        ['type' => 'Co-living suite', 'property' => 'tema-residences', 'number' => 'CL-25', 'bedrooms' => 1, 'bathrooms' => 1, 'sqft' => 350, 'rent' => 3500, 'deposit' => 3500, 'amenities' => ['Shared lounge', 'Co-working space', 'Wi-Fi', 'Cleaning'], 'listing' => 'active', 'availability' => 'available', 'contract' => null, 'tenant' => null, 'months' => 0, 'standing' => null],
        ['type' => 'Serviced apartment', 'property' => 'tema-residences', 'number' => 'SV-11', 'bedrooms' => 1, 'bathrooms' => 1, 'sqft' => 800, 'rent' => 4000, 'deposit' => 4000, 'amenities' => ['Housekeeping', 'Gym', 'Wi-Fi'], 'listing' => 'draft', 'availability' => 'unlisted', 'contract' => null, 'tenant' => null, 'months' => 0, 'standing' => null],

        // --- Edge-state leases (financial + contract-lifecycle coverage) --------
        // Late-fee lease: occupied by the late-fee tenant. Off-market (inactive
        // listing) like the other occupied units. Balance = one overdue month + fee.
        ['type' => 'Two-bedroom apartment', 'property' => 'harbour-view', 'number' => 'GA-06', 'bedrooms' => 2, 'bathrooms' => 2, 'sqft' => 980, 'rent' => 2600, 'deposit' => 5200, 'amenities' => ['Air conditioning', 'Parking', 'Security'], 'listing' => 'inactive', 'availability' => 'occupied', 'contract' => 'active', 'tenant' => 'tenant.latefee', 'months' => 3, 'standing' => 'latefee'],
        // Terminated lease: the tenant left early; the unit is back on the market
        // (available + active listing) and carries its historical terminated contract.
        ['type' => 'Townhouse', 'property' => 'garden-villas', 'number' => 'TH-C', 'bedrooms' => 3, 'bathrooms' => 2, 'sqft' => 1750, 'rent' => 5500, 'deposit' => 11000, 'amenities' => ['Garage', 'Private garden', 'Security'], 'listing' => 'active', 'availability' => 'available', 'contract' => 'terminated', 'tenant' => 'tenant.former', 'months' => 5, 'standing' => 'former'],
        // Expired lease: a full 12-month term that ran its course; unit re-listed.
        ['type' => 'One-bedroom apartment', 'property' => 'ridge-court', 'number' => '1B-08', 'bedrooms' => 1, 'bathrooms' => 1, 'sqft' => 620, 'rent' => 2900, 'deposit' => 5800, 'amenities' => ['Air conditioning', 'Fibre internet', 'Security'], 'listing' => 'active', 'availability' => 'available', 'contract' => 'expired', 'tenant' => 'tenant.expired', 'months' => 12, 'standing' => 'former'],
    ];

    /**
     * Platform feature definitions — the master gateable-feature list.
     * Shared by BOTH modes (this is reference/system data, safe for production).
     * `requires_features` is intentionally null everywhere to keep enablement
     * order-independent; identity-gated features set requires_verification.
     */
    public const FEATURES = [
        ['key' => 'listings', 'name' => 'Property Listings', 'description' => 'Create and publish property listings.', 'requires_verification' => false, 'default' => true],
        ['key' => 'applications', 'name' => 'Rental Applications', 'description' => 'Receive and decide on tenant applications.', 'requires_verification' => true, 'default' => false],
        ['key' => 'leases', 'name' => 'Digital Leases', 'description' => 'Draft and send contracts to tenants.', 'requires_verification' => true, 'default' => false],
        ['key' => 'payments', 'name' => 'Online Payments', 'description' => 'Collect rent and deposits online.', 'requires_verification' => true, 'default' => false],
        ['key' => 'maintenance', 'name' => 'Maintenance Requests', 'description' => 'Track and resolve maintenance requests.', 'requires_verification' => false, 'default' => false],
    ];

    /** Feature keys granted to a landlord by feature tier. */
    public const FEATURE_TIERS = [
        'full' => ['listings', 'applications', 'leases', 'payments', 'maintenance'],
        'limited' => ['listings'],
        'none' => [],
    ];

    /** Resolve a demo user key to a full email on the configured test domain. */
    public static function email(string $key): string
    {
        return $key.'@'.config('seed.development.email_domain', 'wyncrest.test');
    }

    /** All occupied units that carry an ACTIVE contract (the live lease graph). */
    public static function leasedUnits(): array
    {
        return array_values(array_filter(
            self::UNITS,
            fn (array $u) => $u['contract'] === 'active' && $u['tenant'] !== null,
        ));
    }

    /**
     * Every unit that carries a contract in ANY lifecycle state — active AND
     * historical (terminated/expired). Used by the contract/ledger/application/
     * review seeders, which must build history for former leases too.
     */
    public static function contractedUnits(): array
    {
        return array_values(array_filter(
            self::UNITS,
            fn (array $u) => $u['contract'] !== null && $u['tenant'] !== null,
        ));
    }

    /** Units whose lease has ENDED (terminated or expired) — former tenants. */
    public static function formerLeaseUnits(): array
    {
        return array_values(array_filter(
            self::UNITS,
            fn (array $u) => in_array($u['contract'], ['terminated', 'expired'], true) && $u['tenant'] !== null,
        ));
    }
}
